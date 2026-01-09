<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Output\Renderers;

use DateTimeImmutable;
use PHPeek\LaravelQueueAutoscale\Output\Contracts\OutputRendererContract;
use PHPeek\LaravelQueueAutoscale\Output\DataTransferObjects\JobActivity;
use PHPeek\LaravelQueueAutoscale\Output\DataTransferObjects\OutputData;
use PHPeek\LaravelQueueAutoscale\Output\Tui\Tabs\JobsTab;
use PHPeek\LaravelQueueAutoscale\Output\Tui\Tabs\LogsTab;
use PHPeek\LaravelQueueAutoscale\Output\Tui\Tabs\MetricsTab;
use PHPeek\LaravelQueueAutoscale\Output\Tui\Tabs\OverviewTab;
use PHPeek\LaravelQueueAutoscale\Output\Tui\Tabs\QueuesTab;
use PHPeek\LaravelQueueAutoscale\Output\Tui\Tabs\WorkersTab;
use PHPeek\LaravelQueueAutoscale\Output\Tui\TuiApplication;

final class TuiOutputRenderer implements OutputRendererContract
{
    private ?TuiApplication $app = null;

    private bool $initialized = false;

    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->app = new TuiApplication;

        // Register all tabs
        $this->app->registerTab(new OverviewTab);
        $this->app->registerTab(new QueuesTab);
        $this->app->registerTab(new WorkersTab);
        $this->app->registerTab(new JobsTab);
        $this->app->registerTab(new LogsTab);
        $this->app->registerTab(new MetricsTab);

        $this->app->initialize();
        $this->initialized = true;
    }

    public function render(OutputData $data): void
    {
        if ($this->app === null) {
            return;
        }

        $app = $this->app;

        // Update state from data
        $app->updateData($data);

        // Record metrics for sparklines
        $this->recordMetrics($data);

        // Process events (keyboard input)
        $shouldContinue = $app->processEvents();

        if (! $shouldContinue) {
            // Signal exit - in a real app, this would break the main loop
            return;
        }

        // Process any queued actions
        $this->processActions();

        // Render the UI
        $app->render();
    }

    /**
     * Fast render tick - only process events and render UI
     * Called at ~60 FPS for responsive keyboard handling
     */
    public function renderTick(): void
    {
        if ($this->app === null) {
            return;
        }

        // Process events (keyboard input) - this is the critical fast path
        $this->app->processEvents();

        // Render the UI (has internal rate limiting)
        $this->app->render();
    }

    public function handleWorkerOutput(int $pid, string $line): void
    {
        if ($this->app === null) {
            return;
        }

        // Add to worker logs for the Logs tab
        $this->app->addWorkerLog($pid, $line);

        // Parse job activity for the Jobs tab
        $activity = $this->parseWorkerOutput($pid, $line);
        if ($activity !== null) {
            $this->app->getState()->addJobActivity($activity);
        }
    }

    public function shutdown(): void
    {
        if ($this->app !== null) {
            $this->app->shutdown();
            $this->app = null;
        }
        $this->initialized = false;
    }

    /**
     * Check if the TUI should exit
     */
    public function shouldExit(): bool
    {
        return $this->app?->getState()->shouldExit ?? false;
    }

    /**
     * Check if user has been actively interacting recently
     * Used to defer expensive operations during navigation
     */
    public function isUserActive(float $seconds = 2.0): bool
    {
        return $this->app?->getState()->isUserActive($seconds) ?? false;
    }

    /**
     * Get queued actions for external processing
     *
     * @return array<string, mixed>
     */
    public function getActionQueue(): array
    {
        return $this->app?->processActionQueue() ?? [];
    }

    private function recordMetrics(OutputData $data): void
    {
        if ($this->app === null) {
            return;
        }

        $state = $this->app->getState();

        // Record worker count
        $state->recordMetric('workers', (float) count($data->workers));

        // Record total queue depth
        $totalDepth = 0;
        foreach ($data->queueStats as $stats) {
            $totalDepth += $stats->depth;
            $state->recordMetric("throughput:{$stats->queue}", $stats->throughputPerMinute);
        }
        $state->recordMetric('queue_depth', (float) $totalDepth);

        // Record jobs processed
        $state->recordMetric('jobs_processed', (float) count($data->recentJobs));
    }

    private function processActions(): void
    {
        $actions = $this->app?->processActionQueue() ?? [];

        foreach ($actions as $name => $result) {
            if (str_starts_with($name, 'command:')) {
                // Handle command palette commands
                // In a real implementation, this would dispatch to the AutoscaleManager
            }
        }
    }

    private function parseWorkerOutput(int $pid, string $line): ?JobActivity
    {
        // Laravel 12+ format: "2026-01-08 20:55:00 App\Jobs\TestJob ........ RUNNING"
        if (preg_match('/RUNNING\s*$/', $line)) {
            // Extract job class - it's between the timestamp and dots
            if (preg_match('/^\s*\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}\s+(.+?)\s*\.+\s*RUNNING/', $line, $matches)) {
                return new JobActivity(
                    workerId: $pid,
                    jobClass: trim($matches[1]),
                    status: 'processing',
                    durationMs: null,
                    timestamp: new DateTimeImmutable,
                );
            }
        }

        // Laravel 12+ format: "2026-01-08 20:55:00 App\Jobs\TestJob ........ 245ms DONE"
        if (preg_match('/DONE\s*$/', $line)) {
            // Extract job class and duration
            if (preg_match('/^\s*\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}\s+(.+?)\s*\.+\s*(\d+(?:\.\d+)?)(m?s)\s+DONE/', $line, $matches)) {
                $duration = (float) $matches[2];
                if ($matches[3] === 's') {
                    $duration *= 1000;
                }

                return new JobActivity(
                    workerId: $pid,
                    jobClass: trim($matches[1]),
                    status: 'processed',
                    durationMs: (int) $duration,
                    timestamp: new DateTimeImmutable,
                );
            }
        }

        // Laravel 12+ format: "2026-01-08 20:55:00 App\Jobs\TestJob ........ 60yrs... FAIL"
        // Or with normal duration: "2026-01-08 20:55:00 App\Jobs\TestJob ........ 245ms FAIL"
        if (preg_match('/FAIL\s*$/', $line)) {
            // Extract job class (duration might be corrupt like "60yrs 10mos...")
            if (preg_match('/^\s*\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}\s+(.+?)\s*\.+/', $line, $matches)) {
                // Try to extract valid duration
                $durationMs = null;
                if (preg_match('/(\d+(?:\.\d+)?)(m?s)\s+FAIL/', $line, $durationMatches)) {
                    $duration = (float) $durationMatches[1];
                    if ($durationMatches[2] === 's') {
                        $duration *= 1000;
                    }
                    $durationMs = (int) $duration;
                }

                return new JobActivity(
                    workerId: $pid,
                    jobClass: trim($matches[1]),
                    status: 'failed',
                    durationMs: $durationMs,
                    timestamp: new DateTimeImmutable,
                );
            }
        }

        // Legacy Laravel format fallback: "Processing: App\Jobs\TestJob"
        if (preg_match('/Processing:\s+(.+)$/', $line, $matches)) {
            return new JobActivity(
                workerId: $pid,
                jobClass: trim($matches[1]),
                status: 'processing',
                durationMs: null,
                timestamp: new DateTimeImmutable,
            );
        }

        // Legacy Laravel format: "Processed: App\Jobs\TestJob (245ms)"
        if (preg_match('/Processed:\s+(.+?)\s+\((\d+(?:\.\d+)?)(m?s)\)/', $line, $matches)) {
            $duration = (float) $matches[2];
            if ($matches[3] === 's') {
                $duration *= 1000;
            }

            return new JobActivity(
                workerId: $pid,
                jobClass: trim($matches[1]),
                status: 'processed',
                durationMs: (int) $duration,
                timestamp: new DateTimeImmutable,
            );
        }

        // Legacy Laravel format: "Failed: App\Jobs\TestJob"
        if (preg_match('/Failed:\s+(.+)$/', $line, $matches)) {
            return new JobActivity(
                workerId: $pid,
                jobClass: trim($matches[1]),
                status: 'failed',
                durationMs: null,
                timestamp: new DateTimeImmutable,
            );
        }

        return null;
    }
}
