<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Manager;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PHPeek\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use PHPeek\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use PHPeek\LaravelQueueAutoscale\Events\ScalingDecisionMade;
use PHPeek\LaravelQueueAutoscale\Events\SlaBreachPredicted;
use PHPeek\LaravelQueueAutoscale\Events\WorkersScaled;
use PHPeek\LaravelQueueAutoscale\Policies\PolicyExecutor;
use PHPeek\LaravelQueueAutoscale\Scaling\ScalingDecision;
use PHPeek\LaravelQueueAutoscale\Scaling\ScalingEngine;
use PHPeek\LaravelQueueAutoscale\Workers\WorkerPool;
use PHPeek\LaravelQueueAutoscale\Workers\WorkerSpawner;
use PHPeek\LaravelQueueAutoscale\Workers\WorkerTerminator;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;
use PHPeek\LaravelQueueMetrics\Facades\QueueMetrics;

final class AutoscaleManager
{
    private WorkerPool $pool;

    private int $interval = 5;

    private array $lastScaleTime = [];

    public function __construct(
        private readonly ScalingEngine $engine,
        private readonly WorkerSpawner $spawner,
        private readonly WorkerTerminator $terminator,
        private readonly PolicyExecutor $policies,
        private readonly SignalHandler $signals,
    ) {
        $this->pool = new WorkerPool;
    }

    public function configure(int $interval): void
    {
        $this->interval = $interval;
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

        while (! $this->signals->shouldStop()) {
            $this->signals->dispatch();

            try {
                $this->evaluateAndScale();
                $this->cleanupDeadWorkers();
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

        $this->shutdown();

        return 0;
    }

    private function evaluateAndScale(): void
    {
        // Get ALL queues with metrics from laravel-queue-metrics
        // Returns: ['redis:default' => [...metrics array...], ...]
        $allQueues = QueueMetrics::getAllQueuesWithMetrics();

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
     * Map field names from getAllQueuesWithMetrics() to QueueMetricsData::fromArray() format
     */
    private function mapMetricsFields(array $data): array
    {
        return [
            'connection' => $data['connection'] ?? 'default',
            'queue' => $data['queue'] ?? 'default',
            'depth' => $data['depth'] ?? 0,
            'pending' => $data['pending'] ?? 0,
            'scheduled' => $data['scheduled'] ?? 0,
            'reserved' => $data['reserved'] ?? 0,
            'oldest_job_age' => (int) ($data['oldest_job_age_seconds'] ?? 0),
            'age_status' => $data['oldest_job_age_status'] ?? 'normal',
            'throughput_per_minute' => $data['throughput_per_minute'] ?? 0.0,
            'avg_duration' => ($data['avg_duration_ms'] ?? 0.0) / 1000.0, // Convert ms to seconds
            'failure_rate' => $data['failure_rate'] ?? 0.0,
            'utilization_rate' => $data['utilization_rate'] ?? 0.0,
            'active_workers' => $data['active_workers'] ?? 0,
            'driver' => $data['driver'] ?? 'unknown',
            'health' => $data['health'] ?? [],
            'calculated_at' => $data['timestamp'] ?? now()->toIso8601String(),
        ];
    }

    private function evaluateQueue(string $connection, string $queue, QueueMetricsData $metrics): void
    {
        // 1. Get configuration
        $config = QueueConfiguration::fromConfig($connection, $queue);

        // 2. Check cooldown
        $key = "{$connection}:{$queue}";
        if ($this->inCooldown($key, $config->scaleCooldownSeconds)) {
            return;
        }

        // 3. Count current workers
        $currentWorkers = $this->pool->count($connection, $queue);

        // 4. Calculate scaling decision
        $decision = $this->engine->evaluate($metrics, $config, $currentWorkers);

        // 5. Execute policies (before)
        $this->policies->beforeScaling($decision);

        // 6. Execute scaling action
        if ($decision->shouldScaleUp()) {
            $this->scaleUp($decision);
        } elseif ($decision->shouldScaleDown()) {
            $this->scaleDown($decision);
        }

        // 7. Execute policies (after)
        $this->policies->afterScaling($decision);

        // 8. Broadcast events
        event(new ScalingDecisionMade($decision));

        if ($decision->isSlaBreachRisk()) {
            event(new SlaBreachPredicted($decision));
        }

        // 9. Update last scale time
        if (! $decision->shouldHold()) {
            $this->lastScaleTime[$key] = now();
        }
    }

    private function scaleUp(ScalingDecision $decision): void
    {
        $toAdd = $decision->workersToAdd();

        $workers = $this->spawner->spawn(
            $decision->connection,
            $decision->queue,
            $toAdd
        );

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

        $workers = $this->pool->remove(
            $decision->connection,
            $decision->queue,
            $toRemove
        );

        foreach ($workers as $worker) {
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

        foreach ($dead as $worker) {
            $this->pool->removeWorker($worker);

            Log::channel(AutoscaleConfiguration::logChannel())->warning(
                'Removed dead worker',
                ['pid' => $worker->pid()]
            );
        }
    }

    private function shutdown(): void
    {
        Log::channel(AutoscaleConfiguration::logChannel())->info(
            'Shutting down autoscale manager, terminating all workers'
        );

        foreach ($this->pool->all() as $worker) {
            $this->terminator->terminate($worker);
        }
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
}
