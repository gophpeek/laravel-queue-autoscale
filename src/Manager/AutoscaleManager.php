<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Manager;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PHPeek\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use PHPeek\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use PHPeek\LaravelQueueAutoscale\Events\ScalingDecisionMade;
use PHPeek\LaravelQueueAutoscale\Events\SlaBreached;
use PHPeek\LaravelQueueAutoscale\Events\SlaBreachPredicted;
use PHPeek\LaravelQueueAutoscale\Events\SlaRecovered;
use PHPeek\LaravelQueueAutoscale\Events\WorkersScaled;
use PHPeek\LaravelQueueAutoscale\Output\Contracts\OutputRendererContract;
use PHPeek\LaravelQueueAutoscale\Output\DataTransferObjects\OutputData;
use PHPeek\LaravelQueueAutoscale\Output\DataTransferObjects\QueueStats;
use PHPeek\LaravelQueueAutoscale\Output\DataTransferObjects\WorkerStatus;
use PHPeek\LaravelQueueAutoscale\Policies\PolicyExecutor;
use PHPeek\LaravelQueueAutoscale\Scaling\ScalingDecision;
use PHPeek\LaravelQueueAutoscale\Scaling\ScalingEngine;
use PHPeek\LaravelQueueAutoscale\Workers\WorkerOutputBuffer;
use PHPeek\LaravelQueueAutoscale\Workers\WorkerPool;
use PHPeek\LaravelQueueAutoscale\Workers\WorkerSpawner;
use PHPeek\LaravelQueueAutoscale\Workers\WorkerTerminator;
use PHPeek\LaravelQueueMetrics\Actions\CalculateQueueMetricsAction;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;
use PHPeek\LaravelQueueMetrics\Facades\QueueMetrics;
use Symfony\Component\Console\Output\OutputInterface;

final class AutoscaleManager
{
    private WorkerPool $pool;

    private int $interval = 5;

    /**
     * @var array<string, \Illuminate\Support\Carbon>
     */
    private array $lastScaleTime = [];

    /**
     * @var array<string, string>
     */
    private array $lastScaleDirection = [];

    /**
     * @var array<string, bool>
     */
    private array $breachState = [];

    private ?OutputInterface $output = null;

    private ?OutputRendererContract $renderer = null;

    private WorkerOutputBuffer $outputBuffer;

    /** @var array<string, QueueStats> */
    private array $currentQueueStats = [];

    /** @var array<int, string> */
    private array $scalingLog = [];

    /** @var array<string, array<string, mixed>> Cached raw metrics from QueueMetrics */
    private array $cachedMetrics = [];

    public function __construct(
        private readonly ScalingEngine $engine,
        private readonly WorkerSpawner $spawner,
        private readonly WorkerTerminator $terminator,
        private readonly PolicyExecutor $policies,
        private readonly SignalHandler $signals,
    ) {
        $this->pool = new WorkerPool;
        $this->outputBuffer = new WorkerOutputBuffer;
    }

    public function configure(int $interval): void
    {
        $this->interval = $interval;
    }

    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function setRenderer(OutputRendererContract $renderer): void
    {
        $this->renderer = $renderer;
    }

    private function verbose(string $message, string $level = 'info'): void
    {
        if (! $this->output) {
            return;
        }

        if (! $this->output->isVerbose()) {
            return;
        }

        $timestamp = now()->format('H:i:s');
        $prefix = (string) match ($level) {
            'debug' => '<fg=gray>[DEBUG]</>',
            'info' => '<fg=cyan>[INFO]</>',
            'warn' => '<fg=yellow>[WARN]</>',
            'error' => '<fg=red>[ERROR]</>',
            default => '[INFO]',
        };

        $this->output->writeln("[$timestamp] {$prefix} {$message}");
    }

    private function isVeryVerbose(): bool
    {
        if (! $this->output) {
            return false;
        }

        return $this->output->isVeryVerbose();
    }

    public function run(): int
    {
        Log::channel(AutoscaleConfiguration::logChannel())->info(
            'Autoscale manager started',
            [
                'manager_id' => AutoscaleConfiguration::managerId(),
                'interval' => $this->interval,
            ]
        );

        $this->signals->register(function () {
            Log::channel(AutoscaleConfiguration::logChannel())->info(
                'Shutdown signal received'
            );
        });

        $this->renderer?->initialize();

        $isTuiMode = $this->renderer instanceof \PHPeek\LaravelQueueAutoscale\Output\Renderers\TuiOutputRenderer;

        if ($isTuiMode) {
            /** @var \PHPeek\LaravelQueueAutoscale\Output\Renderers\TuiOutputRenderer $tuiRenderer */
            $tuiRenderer = $this->renderer;
            $this->runTuiLoop($tuiRenderer);
        } else {
            $this->runStandardLoop();
        }

        $this->shutdown();

        return 0;
    }

    /**
     * Fast TUI loop - optimized for responsiveness
     *
     * Architecture:
     * - UI rendering runs at 60 FPS (never blocked)
     * - Data fetching happens in small chunks, interleaved with UI ticks
     * - Scaling decisions use cached data
     */
    private function runTuiLoop(\PHPeek\LaravelQueueAutoscale\Output\Renderers\TuiOutputRenderer $tuiRenderer): void
    {
        $lastScalingTime = 0;
        $lastWorkerOutputTime = 0;
        $lastRenderTime = 0;
        $workerOutputInterval = 1;
        $renderInterval = 1; // Update TUI data every 1 second

        // Initial data fetch (blocking, but only once at startup)
        $this->refreshMetricsCache();
        $this->renderOutput();

        while (! $this->signals->shouldStop()) {
            $this->signals->dispatch();

            $now = time();

            try {
                // FAST PATH: UI events and rendering (~60 FPS)
                // This should NEVER be blocked by data operations
                $tuiRenderer->renderTick();

                if ($tuiRenderer->shouldExit()) {
                    $this->signals->requestStop();
                    break;
                }

                // MEDIUM PATH: Worker output + TUI data refresh (every 1 second)
                if ($now - $lastWorkerOutputTime >= $workerOutputInterval) {
                    $this->processWorkerOutput();
                    $this->processTuiActions();
                    $lastWorkerOutputTime = $now;
                }

                // MEDIUM PATH: Render output update (every 1 second)
                // Uses CACHED data, so it's fast
                if ($now - $lastRenderTime >= $renderInterval) {
                    $this->renderOutputFromCache();
                    $lastRenderTime = $now;
                }

                // SLOW PATH: Metrics fetch + scaling (every interval seconds)
                // Only if user is NOT actively interacting
                if ($now - $lastScalingTime >= $this->interval) {
                    if (! $tuiRenderer->isUserActive(1.0)) {
                        $this->refreshMetricsAndScale($tuiRenderer);
                    }
                    $lastScalingTime = $now;
                }
            } catch (\Throwable $e) {
                Log::channel(AutoscaleConfiguration::logChannel())->error(
                    'Autoscale evaluation failed',
                    [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                );
            }

            // ~60 FPS tick
            usleep(16666);
        }
    }

    /**
     * Refresh metrics cache from QueueMetrics
     * This is the expensive operation - should be called sparingly
     *
     * First recalculates queue-level metrics to ensure throughput uses
     * the current 60-second sliding window, then fetches all metrics.
     */
    private function refreshMetricsCache(): void
    {
        // Recalculate metrics first to ensure throughput uses current sliding window
        // This is necessary because throughput is stored as a snapshot and won't
        // automatically decay - it needs to be recalculated from job completion times
        app(CalculateQueueMetricsAction::class)->executeForAllQueues();

        $this->cachedMetrics = QueueMetrics::getAllQueuesWithMetrics();
    }

    /**
     * Refresh metrics and run scaling evaluation
     * Yields to UI between operations to stay responsive
     */
    private function refreshMetricsAndScale(\PHPeek\LaravelQueueAutoscale\Output\Renderers\TuiOutputRenderer $tuiRenderer): void
    {
        // Fetch new metrics (this is the slow part)
        $this->refreshMetricsCache();

        // Yield to UI immediately after fetch
        $tuiRenderer->renderTick();
        if ($tuiRenderer->shouldExit()) {
            return;
        }

        // Process each queue using cached data
        foreach ($this->cachedMetrics as $queueKey => $metricsArray) {
            try {
                $mappedData = $this->mapMetricsFields($metricsArray);
                $metrics = QueueMetricsData::fromArray($mappedData);
                $this->evaluateQueue($metrics->connection, $metrics->queue, $metrics);
            } catch (\Throwable $e) {
                $this->verbose("Failed to process queue {$queueKey}: {$e->getMessage()}", 'error');
                // Continue with other queues
            }

            // Yield to UI between queues
            $tuiRenderer->renderTick();
            if ($tuiRenderer->shouldExit()) {
                return;
            }
        }

        // Cleanup and render
        $this->cleanupDeadWorkers();
        $this->renderOutputFromCache();
    }

    /**
     * Render output using cached metrics data with fresh queue depth
     *
     * Queue depth (pending/reserved/scheduled) is fetched live because it's fast
     * and provides accurate real-time feedback. Throughput and other metrics
     * use cached data since they require more expensive calculations.
     */
    private function renderOutputFromCache(): void
    {
        if ($this->renderer === null) {
            return;
        }

        // Update currentQueueStats from cached metrics with fresh queue depth
        foreach ($this->cachedMetrics as $queueKey => $metricsArray) {
            try {
                $mappedData = $this->mapMetricsFields($metricsArray);
                $metrics = QueueMetricsData::fromArray($mappedData);

                // Fetch fresh queue depth (fast operation - direct Redis/DB read)
                $freshDepth = QueueMetrics::getQueueDepth($metrics->connection, $metrics->queue);
                $oldestJobAgeSeconds = $freshDepth->oldestPendingJobAge !== null
                    ? (int) $freshDepth->oldestPendingJobAge->diffInSeconds(now())
                    : 0;

                $queueConfig = QueueConfiguration::fromConfig($metrics->connection, $metrics->queue);
                $slaTarget = $queueConfig->maxPickupTimeSeconds;
                $workerCount = $this->pool->count($metrics->connection, $metrics->queue);

                $this->currentQueueStats[$queueKey] = new QueueStats(
                    connection: $metrics->connection,
                    queue: $metrics->queue,
                    depth: $freshDepth->totalJobs(),
                    pending: $freshDepth->pendingJobs,
                    oldestJobAge: $oldestJobAgeSeconds,
                    throughputPerMinute: $metrics->throughputPerMinute,
                    activeWorkers: $workerCount,
                    targetWorkers: $workerCount,
                    slaTarget: $slaTarget,
                    slaStatus: $this->calculateSlaStatus($oldestJobAgeSeconds, $slaTarget),
                    reserved: $freshDepth->reservedJobs,
                    scheduled: $freshDepth->delayedJobs,
                );
            } catch (\Throwable $e) {
                $this->verbose("Failed to render queue {$queueKey}: {$e->getMessage()}", 'error');
                // Continue with other queues
            }
        }

        $outputData = $this->buildOutputData();
        $this->renderer?->render($outputData);

        $this->scalingLog = [];
    }

    /**
     * Calculate SLA status based on oldest job age and SLA target
     */
    private function calculateSlaStatus(int $oldestJobAge, int $slaTarget): string
    {
        if ($oldestJobAge >= $slaTarget) {
            return 'breached';
        }

        if ($oldestJobAge > $slaTarget * 0.8) {
            return 'warning';
        }

        return 'ok';
    }

    /**
     * Standard loop for non-TUI modes
     */
    private function runStandardLoop(): void
    {
        while (! $this->signals->shouldStop()) {
            $this->signals->dispatch();

            try {
                $this->processWorkerOutput();
                $this->evaluateAndScale();
                $this->cleanupDeadWorkers();
                $this->renderOutput();
                $this->processTuiActions();
            } catch (\Throwable $e) {
                Log::channel(AutoscaleConfiguration::logChannel())->error(
                    'Autoscale evaluation failed',
                    [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                );
            }

            sleep($this->interval);
        }
    }

    private function evaluateAndScale(): void
    {
        // Recalculate metrics first to ensure throughput uses current sliding window
        app(CalculateQueueMetricsAction::class)->executeForAllQueues();

        // Get ALL queues with metrics from laravel-queue-metrics
        // Returns: ['redis:default' => [...metrics array...], ...]
        $allQueues = QueueMetrics::getAllQueuesWithMetrics();

        // Also include configured queues that might not have historical data yet
        // This ensures newly configured queues are monitored from the start
        $configuredQueues = AutoscaleConfiguration::configuredQueues();
        foreach ($configuredQueues as $queueKey => $queueInfo) {
            if (! isset($allQueues[$queueKey])) {
                // Fetch fresh metrics for this queue directly
                $allQueues[$queueKey] = $this->getMetricsForQueue($queueInfo['connection'], $queueInfo['queue']);
            }
        }

        // Cache the merged metrics for TUI/render
        $this->cachedMetrics = $allQueues;

        foreach ($allQueues as $queueKey => $metricsArray) {
            // Map field names from API response to DTO format
            $mappedData = $this->mapMetricsFields($metricsArray);

            // Convert array to QueueMetricsData DTO
            $metrics = QueueMetricsData::fromArray($mappedData);

            // Extract connection and queue from the DTO
            $this->evaluateQueue($metrics->connection, $metrics->queue, $metrics);
        }
    }

    /**
     * Get metrics for a specific queue directly (bypasses discovery).
     *
     * @return array<string, mixed>
     */
    private function getMetricsForQueue(string $connection, string $queue): array
    {
        // Get queue depth directly from the queue inspector
        $depth = QueueMetrics::getQueueDepth($connection, $queue);
        $queueMetrics = QueueMetrics::getQueueMetrics($connection, $queue);

        // Calculate oldest job age in seconds from Carbon instance
        $oldestJobAgeSeconds = 0;
        if ($depth->oldestPendingJobAge !== null) {
            $oldestJobAgeSeconds = (int) $depth->oldestPendingJobAge->diffInSeconds(now());
        }

        $total = $depth->pendingJobs + $depth->delayedJobs + $depth->reservedJobs;

        return [
            'connection' => $connection,
            'queue' => $queue,
            'driver' => (string) config("queue.connections.{$connection}.driver", 'unknown'),
            'depth' => [
                'total' => $total,
                'pending' => $depth->pendingJobs,
                'scheduled' => $depth->delayedJobs,
                'reserved' => $depth->reservedJobs,
                'oldest_job_age_seconds' => $oldestJobAgeSeconds,
                'oldest_job_age_status' => $queueMetrics->ageStatus,
            ],
            'performance_60s' => [
                'throughput_per_minute' => $queueMetrics->throughputPerMinute,
                'avg_duration_ms' => $queueMetrics->avgDuration, // Already in ms from metrics package
                'window_seconds' => 60,
            ],
            'lifetime' => [
                'failure_rate_percent' => $queueMetrics->failureRate,
            ],
            'workers' => [
                'active_count' => $queueMetrics->activeWorkers,
                'current_busy_percent' => $queueMetrics->utilizationRate,
                'lifetime_busy_percent' => 0,
            ],
            'baseline' => null,
            'trends' => [],
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Map field names from getAllQueuesWithMetrics() to QueueMetricsData::fromArray() format
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapMetricsFields(array $data): array
    {
        // Merge baseline and trends data into health array
        // These will be passed through to HealthStats::fromArray() but ignored by it
        // We'll access them as raw array data in the strategy
        $healthBase = $data['health'] ?? [];
        $healthData = array_merge(
            is_array($healthBase) ? $healthBase : [],
            [
                'baseline' => $data['baseline'] ?? null,
                'trend' => $data['trends'] ?? null,
                'percentiles' => $data['percentiles'] ?? null,
            ]
        );

        // Extract nested depth data
        /** @var array<string, mixed>|int $depthData */
        $depthData = $data['depth'] ?? [];
        $depth = is_array($depthData) ? (int) ($depthData['total'] ?? 0) : (int) $depthData;
        $pending = is_array($depthData) ? (int) ($depthData['pending'] ?? 0) : 0;
        $scheduled = is_array($depthData) ? (int) ($depthData['scheduled'] ?? 0) : 0;
        $reserved = is_array($depthData) ? (int) ($depthData['reserved'] ?? 0) : 0;
        $oldestJobAge = is_array($depthData) ? (int) ($depthData['oldest_job_age_seconds'] ?? 0) : 0;

        // Extract nested performance data
        /** @var array<string, mixed> $perfData */
        $perfData = is_array($data['performance_60s'] ?? null) ? $data['performance_60s'] : [];
        $throughput = (float) ($perfData['throughput_per_minute'] ?? 0.0);
        $avgDurationMs = (float) ($perfData['avg_duration_ms'] ?? 0.0);

        // Extract nested lifetime data
        /** @var array<string, mixed> $lifetimeData */
        $lifetimeData = is_array($data['lifetime'] ?? null) ? $data['lifetime'] : [];
        $failureRate = (float) ($lifetimeData['failure_rate_percent'] ?? 0.0);

        // Extract nested workers data
        /** @var array<string, mixed> $workersData */
        $workersData = is_array($data['workers'] ?? null) ? $data['workers'] : [];
        $activeWorkers = (int) ($workersData['active_count'] ?? 0);
        $utilizationRate = (float) ($workersData['current_busy_percent'] ?? 0.0);

        return [
            'connection' => $data['connection'] ?? 'default',
            'queue' => $data['queue'] ?? 'default',
            'depth' => $depth,
            'pending' => $pending,
            'scheduled' => $scheduled,
            'reserved' => $reserved,
            'oldest_job_age' => $oldestJobAge,
            'age_status' => $data['oldest_job_age_status'] ?? 'normal',
            'throughput_per_minute' => $throughput,
            'avg_duration' => $avgDurationMs / 1000.0, // Convert ms to seconds
            'failure_rate' => $failureRate,
            'utilization_rate' => $utilizationRate,
            'active_workers' => $activeWorkers,
            'driver' => $data['driver'] ?? 'unknown',
            'health' => $healthData,
            'calculated_at' => $data['timestamp'] ?? now()->toIso8601String(),
        ];
    }

    private function evaluateQueue(string $connection, string $queue, QueueMetricsData $metrics): void
    {
        $this->verbose("Evaluating queue: {$connection}:{$queue}", 'debug');
        $this->verbose("  Metrics: pending={$metrics->pending}, oldest_age={$metrics->oldestJobAge}s, active_workers={$metrics->activeWorkers}, throughput={$metrics->throughputPerMinute}/min", 'debug');

        // Warn if throughput data unavailable (needs historical data)
        if ($metrics->throughputPerMinute === 0.0 && $metrics->activeWorkers > 0) {
            $this->verbose('  ⚠️  Throughput=0 despite active workers - metrics package needs more historical data', 'debug');
        }

        // 1. Get configuration
        $config = QueueConfiguration::fromConfig($connection, $queue);

        // 2. Count current workers
        $currentWorkers = $this->pool->count($connection, $queue);
        $this->verbose("  Current workers: {$currentWorkers}", 'debug');

        // 3. Calculate scaling decision
        $decision = $this->engine->evaluate($metrics, $config, $currentWorkers);

        // 4. Check for SLA breach
        $isBreaching = $metrics->oldestJobAge > 0 && $metrics->oldestJobAge >= $config->maxPickupTimeSeconds;

        if ($isBreaching) {
            $this->verbose("  🚨 SLA BREACH: oldest_age={$metrics->oldestJobAge}s >= SLA={$config->maxPickupTimeSeconds}s", 'error');
        }

        // 5. Anti-flapping check: prevent direction reversals within cooldown
        $key = "{$connection}:{$queue}";
        $currentDirection = $decision->shouldScaleUp() ? 'up' : ($decision->shouldScaleDown() ? 'down' : 'hold');
        $lastDirection = $this->lastScaleDirection[$key] ?? null;

        // Only apply cooldown if direction is reversing (prevents flapping)
        if ($currentDirection !== 'hold' && $lastDirection !== null && $currentDirection !== $lastDirection) {
            if ($this->inCooldown($key, $config->scaleCooldownSeconds)) {
                $remaining = $this->getCooldownRemaining($key, $config->scaleCooldownSeconds);
                $this->verbose("  ⏸️  Anti-flapping: cannot reverse direction during cooldown ({$remaining}s remaining)", 'debug');

                return;
            }
        }

        // Log scaling recommendation
        if ($decision->shouldScaleUp() || $decision->shouldScaleDown()) {
            $this->verbose("  📊 Scaling recommended: current={$currentWorkers} → target={$decision->targetWorkers}", 'debug');
        }

        // 6. Display decision
        $this->verbose("  📊 Decision: {$currentWorkers} → {$decision->targetWorkers} workers", 'info');
        $this->verbose("     Reason: {$decision->reason}", 'info');

        if ($decision->predictedPickupTime !== null) {
            $this->verbose("     Predicted pickup time: {$decision->predictedPickupTime}s (SLA: {$decision->slaTarget}s)", 'info');
        }

        // 6a. Display capacity breakdown in -vvv mode
        if ($decision->capacity !== null && $this->isVeryVerbose()) {
            $this->verbose('     ━━━ Capacity Breakdown ━━━', 'debug');
            foreach ($decision->capacity->getFormattedDetails() as $label => $detail) {
                $this->verbose("     {$label}: {$detail}", 'debug');
            }

            // Explain the capacity factor
            $factor = $decision->capacity->limitingFactor;
            if ($factor === 'cpu' || $factor === 'memory') {
                $this->verbose("     ⚠️  Constrained by system capacity: {$factor}", 'warn');
            } elseif ($factor === 'config') {
                $this->verbose('     ⚠️  Constrained by max_workers config limit', 'warn');
            } elseif ($factor === 'strategy') {
                $this->verbose('     ✓ Optimal worker count determined by demand analysis', 'debug');
            }
        }

        // 6b. Store queue stats for renderer
        $slaStatus = $isBreaching ? 'breached' : ($metrics->oldestJobAge > $config->maxPickupTimeSeconds * 0.8 ? 'warning' : 'ok');
        $this->currentQueueStats[$key] = new QueueStats(
            connection: $connection,
            queue: $queue,
            depth: $metrics->pending,
            pending: $metrics->pending,
            throughputPerMinute: $metrics->throughputPerMinute,
            oldestJobAge: $metrics->oldestJobAge,
            slaTarget: $config->maxPickupTimeSeconds,
            slaStatus: $slaStatus,
            activeWorkers: $currentWorkers,
            targetWorkers: $decision->targetWorkers,
            reserved: $metrics->reserved,
            scheduled: $metrics->scheduled,
        );

        // 7. Execute policies (before) - policies can modify the decision
        $finalDecision = $this->policies->beforeScaling($decision);

        // Log if decision was modified by policies
        if ($finalDecision->targetWorkers !== $decision->targetWorkers) {
            $this->verbose("  🔧 Policy modified decision: {$decision->targetWorkers} → {$finalDecision->targetWorkers} workers", 'info');
        }

        // 8. Execute scaling action using potentially modified decision
        if ($finalDecision->shouldScaleUp()) {
            $this->scaleUp($finalDecision);
        } elseif ($finalDecision->shouldScaleDown()) {
            $this->scaleDown($finalDecision);
        } else {
            $this->verbose('  ✓ No scaling action needed', 'debug');
        }

        // 9. Execute policies (after)
        $this->policies->afterScaling($finalDecision);

        // 10. Broadcast events using final decision
        event(new ScalingDecisionMade($finalDecision));

        if ($finalDecision->isSlaBreachRisk()) {
            $this->verbose('  ⚠️  SLA BREACH RISK DETECTED!', 'warn');
            event(new SlaBreachPredicted($finalDecision));
        }

        // Track SLA breach state and fire breach/recovery events
        $wasBreaching = $this->breachState[$key] ?? false;

        if ($isBreaching && ! $wasBreaching) {
            // Entering breach state - fire SlaBreached
            event(new SlaBreached(
                connection: $config->connection,
                queue: $config->queue,
                oldestJobAge: $metrics->oldestJobAge,
                slaTarget: $config->maxPickupTimeSeconds,
                pending: $metrics->pending,
                activeWorkers: $metrics->activeWorkers,
            ));
            $this->breachState[$key] = true;
        } elseif (! $isBreaching && $wasBreaching) {
            // Recovering from breach - fire SlaRecovered
            event(new SlaRecovered(
                connection: $config->connection,
                queue: $config->queue,
                currentJobAge: $metrics->oldestJobAge,
                slaTarget: $config->maxPickupTimeSeconds,
                pending: $metrics->pending,
                activeWorkers: $metrics->activeWorkers,
            ));
            $this->breachState[$key] = false;
        } elseif ($isBreaching) {
            // Update breach state (still breaching)
            $this->breachState[$key] = true;
        } else {
            // Update breach state (not breaching)
            $this->breachState[$key] = false;
        }

        // 11. Update last scale time and direction
        if (! $finalDecision->shouldHold()) {
            $this->lastScaleTime[$key] = now();
            $this->lastScaleDirection[$key] = $currentDirection;
        }
    }

    private function scaleUp(ScalingDecision $decision): void
    {
        $toAdd = $decision->workersToAdd();

        $this->verbose("  ⬆️  Scaling UP: spawning {$toAdd} worker(s)", 'info');

        $this->scalingLog[] = sprintf(
            '[%s] %s:%s scaled UP %d -> %d (%s)',
            now()->format('H:i:s'),
            $decision->connection,
            $decision->queue,
            $decision->currentWorkers,
            $decision->targetWorkers,
            $decision->reason
        );

        $workers = $this->spawner->spawn(
            $decision->connection,
            $decision->queue,
            $toAdd
        );

        foreach ($workers as $worker) {
            $this->verbose("     ✓ Worker spawned: PID {$worker->pid()}", 'info');
        }

        $this->pool->addMany($workers);

        Log::channel(AutoscaleConfiguration::logChannel())->info(
            'Scaled up workers',
            [
                'connection' => $decision->connection,
                'queue' => $decision->queue,
                'from' => $decision->currentWorkers,
                'to' => $decision->targetWorkers,
                'added' => $toAdd,
                'reason' => $decision->reason,
            ]
        );

        event(new WorkersScaled(
            connection: $decision->connection,
            queue: $decision->queue,
            from: $decision->currentWorkers,
            to: $decision->targetWorkers,
            action: 'up',
            reason: $decision->reason
        ));
    }

    private function scaleDown(ScalingDecision $decision): void
    {
        $toRemove = $decision->workersToRemove();

        $this->verbose("  ⬇️  Scaling DOWN: terminating {$toRemove} worker(s)", 'info');

        $this->scalingLog[] = sprintf(
            '[%s] %s:%s scaled DOWN %d -> %d (%s)',
            now()->format('H:i:s'),
            $decision->connection,
            $decision->queue,
            $decision->currentWorkers,
            $decision->targetWorkers,
            $decision->reason
        );

        $workers = $this->pool->remove(
            $decision->connection,
            $decision->queue,
            $toRemove
        );

        foreach ($workers as $worker) {
            $this->verbose("     ✓ Terminating worker: PID {$worker->pid()}", 'info');
            $this->terminator->terminate($worker);
        }

        Log::channel(AutoscaleConfiguration::logChannel())->info(
            'Scaled down workers',
            [
                'connection' => $decision->connection,
                'queue' => $decision->queue,
                'from' => $decision->currentWorkers,
                'to' => $decision->targetWorkers,
                'removed' => $toRemove,
                'reason' => $decision->reason,
            ]
        );

        event(new WorkersScaled(
            connection: $decision->connection,
            queue: $decision->queue,
            from: $decision->currentWorkers,
            to: $decision->targetWorkers,
            action: 'down',
            reason: $decision->reason
        ));
    }

    private function cleanupDeadWorkers(): void
    {
        $dead = $this->pool->getDeadWorkers();

        if (count($dead) > 0) {
            $this->verbose('🔧 Cleaning up '.count($dead).' dead worker(s)', 'warn');
        }

        foreach ($dead as $worker) {
            $this->pool->removeWorker($worker);

            $this->verbose("   💀 Removed dead worker: PID {$worker->pid()}", 'warn');

            Log::channel(AutoscaleConfiguration::logChannel())->warning(
                'Removed dead worker',
                ['pid' => $worker->pid()]
            );
        }
    }

    private function processWorkerOutput(): void
    {
        if ($this->renderer === null) {
            return;
        }

        $outputLines = $this->outputBuffer->collectOutput($this->pool->all());

        foreach ($outputLines as $pid => $lines) {
            foreach ($lines as $line) {
                $this->renderer->handleWorkerOutput($pid, $line);
            }
        }
    }

    /**
     * Process actions from the TUI
     */
    private function processTuiActions(): void
    {
        if ($this->renderer === null) {
            return;
        }

        $actions = $this->renderer->getActionQueue();

        foreach ($actions as $name => $actionData) {
            // Skip command palette actions for now (handled separately)
            if (str_starts_with($name, 'command:')) {
                continue;
            }

            // Handle worker actions (restart, pause, resume, kill)
            if (is_array($actionData) && isset($actionData['action'], $actionData['pid'])) {
                /** @var array{action: string, pid?: int, queue?: string, count?: int} $actionData */
                $result = $this->handleAction($actionData);
                $this->verbose("TUI Action: {$name} - {$result['message']}", $result['success'] ? 'info' : 'warn');
            }
        }
    }

    private function renderOutput(): void
    {
        if ($this->renderer === null) {
            return;
        }

        $outputData = $this->buildOutputData();
        $this->renderer->render($outputData);

        $this->scalingLog = [];
    }

    private function buildOutputData(): OutputData
    {
        $workers = [];
        $id = 1;
        foreach ($this->pool->all() as $worker) {
            $workers[$id] = new WorkerStatus(
                id: $id,
                pid: $worker->pid(),
                connection: $worker->connection,
                queue: $worker->queue,
                status: $worker->isRunning() ? 'running' : 'dead',
                uptimeSeconds: $worker->uptimeSeconds(),
            );
            $id++;
        }

        return new OutputData(
            queueStats: $this->currentQueueStats,
            workers: $workers,
            recentJobs: [],
            scalingLog: $this->scalingLog,
            timestamp: new \DateTimeImmutable,
        );
    }

    private function shutdown(): void
    {
        $workerCount = count($this->pool->all());

        $this->verbose('🛑 Shutting down autoscale manager', 'info');
        $this->verbose("   Terminating {$workerCount} worker(s)...", 'info');

        Log::channel(AutoscaleConfiguration::logChannel())->info(
            'Shutting down autoscale manager, terminating all workers'
        );

        foreach ($this->pool->all() as $worker) {
            $this->verbose("   ✓ Terminating worker: PID {$worker->pid()}", 'info');
            $this->terminator->terminate($worker);
        }

        $this->renderer?->shutdown();

        $this->verbose('✓ Shutdown complete', 'info');
    }

    private function inCooldown(string $key, int $cooldownSeconds): bool
    {
        if (! isset($this->lastScaleTime[$key])) {
            return false;
        }

        /** @var Carbon $lastScale */
        $lastScale = $this->lastScaleTime[$key];

        return $lastScale->diffInSeconds(now()) < $cooldownSeconds;
    }

    private function getCooldownRemaining(string $key, int $cooldownSeconds): int
    {
        if (! isset($this->lastScaleTime[$key])) {
            return 0;
        }

        /** @var Carbon $lastScale */
        $lastScale = $this->lastScaleTime[$key];
        $elapsed = $lastScale->diffInSeconds(now());

        return (int) max(0, $cooldownSeconds - $elapsed);
    }

    /**
     * Handle an action from the TUI
     *
     * @param  array{action: string, pid?: int, queue?: string, count?: int}  $action
     * @return array{success: bool, message: string}
     */
    public function handleAction(array $action): array
    {
        $actionType = $action['action'];

        return match ($actionType) {
            'restart' => $this->restartWorker($action['pid'] ?? 0),
            'pause' => $this->pauseWorker($action['pid'] ?? 0),
            'resume' => $this->resumeWorker($action['pid'] ?? 0),
            'kill' => $this->killWorker($action['pid'] ?? 0),
            default => ['success' => false, 'message' => "Unknown action: {$actionType}"],
        };
    }

    /**
     * Restart a worker by PID (graceful restart via SIGUSR2)
     *
     * @return array{success: bool, message: string}
     */
    public function restartWorker(int $pid): array
    {
        if ($pid <= 0) {
            return ['success' => false, 'message' => 'Invalid PID'];
        }

        $worker = $this->pool->findByPid($pid);
        if ($worker === null) {
            return ['success' => false, 'message' => "Worker with PID {$pid} not found"];
        }

        if (! $worker->isRunning()) {
            return ['success' => false, 'message' => "Worker with PID {$pid} is not running"];
        }

        // Send SIGUSR2 for graceful restart (Laravel Horizon convention)
        $success = posix_kill($pid, SIGUSR2);

        if ($success) {
            $this->verbose("🔄 Restart signal sent to worker PID {$pid}", 'info');
            Log::channel(AutoscaleConfiguration::logChannel())->info(
                'Worker restart signal sent',
                ['pid' => $pid, 'queue' => $worker->queue]
            );

            return ['success' => true, 'message' => "Restart signal sent to worker {$pid}"];
        }

        return ['success' => false, 'message' => "Failed to send restart signal to worker {$pid}"];
    }

    /**
     * Pause a worker by PID (SIGSTOP)
     *
     * @return array{success: bool, message: string}
     */
    public function pauseWorker(int $pid): array
    {
        if ($pid <= 0) {
            return ['success' => false, 'message' => 'Invalid PID'];
        }

        $worker = $this->pool->findByPid($pid);
        if ($worker === null) {
            return ['success' => false, 'message' => "Worker with PID {$pid} not found"];
        }

        if (! $worker->isRunning()) {
            return ['success' => false, 'message' => "Worker with PID {$pid} is not running"];
        }

        // Send SIGSTOP to pause the process
        $success = posix_kill($pid, SIGSTOP);

        if ($success) {
            $this->verbose("⏸️  Worker PID {$pid} paused", 'info');
            Log::channel(AutoscaleConfiguration::logChannel())->info(
                'Worker paused',
                ['pid' => $pid, 'queue' => $worker->queue]
            );

            return ['success' => true, 'message' => "Worker {$pid} paused"];
        }

        return ['success' => false, 'message' => "Failed to pause worker {$pid}"];
    }

    /**
     * Resume a paused worker by PID (SIGCONT)
     *
     * @return array{success: bool, message: string}
     */
    public function resumeWorker(int $pid): array
    {
        if ($pid <= 0) {
            return ['success' => false, 'message' => 'Invalid PID'];
        }

        $worker = $this->pool->findByPid($pid);
        if ($worker === null) {
            return ['success' => false, 'message' => "Worker with PID {$pid} not found"];
        }

        // Send SIGCONT to resume the process
        $success = posix_kill($pid, SIGCONT);

        if ($success) {
            $this->verbose("▶️  Worker PID {$pid} resumed", 'info');
            Log::channel(AutoscaleConfiguration::logChannel())->info(
                'Worker resumed',
                ['pid' => $pid, 'queue' => $worker->queue]
            );

            return ['success' => true, 'message' => "Worker {$pid} resumed"];
        }

        return ['success' => false, 'message' => "Failed to resume worker {$pid}"];
    }

    /**
     * Kill a worker by PID (immediate termination)
     *
     * @return array{success: bool, message: string}
     */
    public function killWorker(int $pid): array
    {
        if ($pid <= 0) {
            return ['success' => false, 'message' => 'Invalid PID'];
        }

        $worker = $this->pool->findByPid($pid);
        if ($worker === null) {
            return ['success' => false, 'message' => "Worker with PID {$pid} not found"];
        }

        // Use the terminator to properly terminate the worker
        $this->terminator->terminate($worker);
        $this->pool->removeWorker($worker);

        $this->verbose("💀 Worker PID {$pid} killed", 'warn');
        Log::channel(AutoscaleConfiguration::logChannel())->warning(
            'Worker killed via TUI',
            ['pid' => $pid, 'queue' => $worker->queue]
        );

        return ['success' => true, 'message' => "Worker {$pid} killed"];
    }
}
