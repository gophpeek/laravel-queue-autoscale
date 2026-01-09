<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Output\Tui\State;

use DateTimeImmutable;
use PHPeek\LaravelQueueAutoscale\Output\DataTransferObjects\JobActivity;
use PHPeek\LaravelQueueAutoscale\Output\DataTransferObjects\OutputData;
use PHPeek\LaravelQueueAutoscale\Output\DataTransferObjects\QueueStats;
use PHPeek\LaravelQueueAutoscale\Output\DataTransferObjects\WorkerStatus;

final class TuiState
{
    private const MAX_JOBS = 500;

    private const MAX_LOG_ENTRIES = 1000;

    private const MAX_WORKER_LOGS = 10000;

    private const MAX_METRICS_HISTORY = 300; // 5 minutes at 1 sample/sec

    // Navigation state
    public readonly NavigationState $navigation;

    // Filter state
    public readonly FilterState $filter;

    // Command palette state
    public bool $commandMode = false;

    public string $commandInput = '';

    // Confirmation dialog state
    public bool $confirmMode = false;

    public string $confirmMessage = '';

    /** @var callable|null */
    public mixed $confirmCallback = null;

    // Should exit flag
    public bool $shouldExit = false;

    // Last interaction timestamp (for deferring expensive operations)
    public float $lastInteractionTime = 0.0;

    /** @var array<string, QueueStats> */
    private array $queueStats = [];

    /** @var array<int, WorkerStatus> */
    private array $workers = [];

    /** @var array<int, JobActivity> */
    private array $recentJobs = [];

    /** @var array<int, string> */
    private array $scalingLog = [];

    /** @var array<int, array{timestamp: DateTimeImmutable, pid: int, line: string}> */
    private array $workerLogs = [];

    /** @var array<string, array<int, array{timestamp: int, value: float}>> */
    private array $metricsHistory = [];

    public function __construct()
    {
        $this->navigation = new NavigationState;
        $this->filter = new FilterState;
    }

    public function updateFromOutputData(OutputData $data): void
    {
        $this->queueStats = $data->queueStats;
        $this->workers = $data->workers;

        $this->recentJobs = array_merge($this->recentJobs, $data->recentJobs);
        $this->recentJobs = array_slice($this->recentJobs, -self::MAX_JOBS);

        $this->scalingLog = array_merge($this->scalingLog, $data->scalingLog);
        $this->scalingLog = array_slice($this->scalingLog, -self::MAX_LOG_ENTRIES);
    }

    public function addJobActivity(JobActivity $activity): void
    {
        $this->recentJobs[] = $activity;
        $this->recentJobs = array_slice($this->recentJobs, -self::MAX_JOBS);
    }

    /**
     * @return array<string, QueueStats>
     */
    public function getQueueStats(): array
    {
        return $this->queueStats;
    }

    /**
     * @return array<int, WorkerStatus>
     */
    public function getWorkers(): array
    {
        return $this->workers;
    }

    /**
     * @return array<int, JobActivity>
     */
    public function getRecentJobs(): array
    {
        return $this->recentJobs;
    }

    /**
     * @return array<int, string>
     */
    public function getScalingLog(): array
    {
        return $this->scalingLog;
    }

    public function totalWorkers(): int
    {
        return count($this->workers);
    }

    public function runningWorkers(): int
    {
        return count(array_filter($this->workers, fn (WorkerStatus $w) => $w->isRunning()));
    }

    /**
     * Add a worker log entry
     */
    public function addWorkerLog(int $pid, string $line): void
    {
        $this->workerLogs[] = [
            'timestamp' => new DateTimeImmutable,
            'pid' => $pid,
            'line' => $line,
        ];

        // Keep only the last MAX_WORKER_LOGS entries
        if (count($this->workerLogs) > self::MAX_WORKER_LOGS) {
            $this->workerLogs = array_slice($this->workerLogs, -self::MAX_WORKER_LOGS);
        }
    }

    /**
     * @return array<int, array{timestamp: DateTimeImmutable, pid: int, line: string}>
     */
    public function getWorkerLogs(): array
    {
        return $this->workerLogs;
    }

    /**
     * Get filtered worker logs
     *
     * @return array<int, array{timestamp: DateTimeImmutable, pid: int, line: string}>
     */
    public function getFilteredWorkerLogs(int $tab): array
    {
        $query = $this->filter->getTabFilter($tab);
        if ($query === '') {
            return $this->workerLogs;
        }

        return array_filter(
            $this->workerLogs,
            fn (array $log) => FilterState::contains($log['line'], $query)
        );
    }

    /**
     * Record a metric value
     */
    public function recordMetric(string $name, float $value): void
    {
        if (! isset($this->metricsHistory[$name])) {
            $this->metricsHistory[$name] = [];
        }

        $this->metricsHistory[$name][] = [
            'timestamp' => time(),
            'value' => $value,
        ];

        // Keep only the last MAX_METRICS_HISTORY entries
        if (count($this->metricsHistory[$name]) > self::MAX_METRICS_HISTORY) {
            $this->metricsHistory[$name] = array_slice(
                $this->metricsHistory[$name],
                -self::MAX_METRICS_HISTORY
            );
        }
    }

    /**
     * @return array<int, array{timestamp: int, value: float}>
     */
    public function getMetricHistory(string $name): array
    {
        return $this->metricsHistory[$name] ?? [];
    }

    /**
     * @return array<string, array<int, array{timestamp: int, value: float}>>
     */
    public function getAllMetricsHistory(): array
    {
        return $this->metricsHistory;
    }

    /**
     * Get a worker by PID
     */
    public function getWorkerByPid(int $pid): ?WorkerStatus
    {
        foreach ($this->workers as $worker) {
            if ($worker->pid === $pid) {
                return $worker;
            }
        }

        return null;
    }

    /**
     * Get filtered jobs
     *
     * @return array<int, JobActivity>
     */
    public function getFilteredJobs(int $tab): array
    {
        $query = $this->filter->getTabFilter($tab);
        if ($query === '') {
            return $this->recentJobs;
        }

        return array_filter(
            $this->recentJobs,
            fn (JobActivity $job) => FilterState::contains($job->jobClass, $query)
                || FilterState::contains($job->status, $query)
        );
    }

    // Command palette methods
    public function enterCommandMode(): void
    {
        $this->commandMode = true;
        $this->commandInput = '';
    }

    public function exitCommandMode(): void
    {
        $this->commandMode = false;
        $this->commandInput = '';
    }

    public function appendCommandChar(string $char): void
    {
        $this->commandInput .= $char;
    }

    public function backspaceCommand(): void
    {
        if ($this->commandInput !== '') {
            $this->commandInput = mb_substr($this->commandInput, 0, -1);
        }
    }

    // Confirmation dialog methods
    public function showConfirmDialog(string $message, callable $callback): void
    {
        $this->confirmMode = true;
        $this->confirmMessage = $message;
        $this->confirmCallback = $callback;
    }

    public function confirmAction(): void
    {
        if ($this->confirmCallback !== null) {
            ($this->confirmCallback)();
        }
        $this->dismissConfirmDialog();
    }

    public function dismissConfirmDialog(): void
    {
        $this->confirmMode = false;
        $this->confirmMessage = '';
        $this->confirmCallback = null;
    }

    /**
     * Record a user interaction (key press)
     */
    public function recordInteraction(): void
    {
        $this->lastInteractionTime = microtime(true);
    }

    /**
     * Check if user has been active recently
     *
     * @param  float  $seconds  Threshold in seconds
     */
    public function isUserActive(float $seconds = 2.0): bool
    {
        return (microtime(true) - $this->lastInteractionTime) < $seconds;
    }
}
