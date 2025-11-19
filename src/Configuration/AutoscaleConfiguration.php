<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Configuration;

final readonly class AutoscaleConfiguration
{
    public static function isEnabled(): bool
    {
        return (bool) config('queue-autoscale.enabled', true);
    }

    public static function managerId(): string
    {
        return (string) config('queue-autoscale.manager_id', gethostname());
    }

    public static function evaluationIntervalSeconds(): int
    {
        return (int) config('queue-autoscale.manager.evaluation_interval_seconds', 5);
    }

    public static function logChannel(): string
    {
        return (string) config('queue-autoscale.manager.log_channel', 'stack');
    }

    public static function trendWindowSeconds(): int
    {
        return (int) config('queue-autoscale.prediction.trend_window_seconds', 300);
    }

    public static function forecastHorizonSeconds(): int
    {
        return (int) config('queue-autoscale.prediction.forecast_horizon_seconds', 60);
    }

    public static function breachThreshold(): float
    {
        return (float) config('queue-autoscale.prediction.breach_threshold', 0.8);
    }

    public static function maxCpuPercent(): int
    {
        return (int) config('queue-autoscale.limits.max_cpu_percent', 85);
    }

    public static function maxMemoryPercent(): int
    {
        return (int) config('queue-autoscale.limits.max_memory_percent', 85);
    }

    public static function workerMemoryMbEstimate(): int
    {
        return (int) config('queue-autoscale.limits.worker_memory_mb_estimate', 128);
    }

    public static function reserveCpuCores(): int
    {
        return (int) config('queue-autoscale.limits.reserve_cpu_cores', 1);
    }

    public static function workerTimeoutSeconds(): int
    {
        return (int) config('queue-autoscale.workers.timeout_seconds', 3600);
    }

    public static function workerTries(): int
    {
        return (int) config('queue-autoscale.workers.tries', 3);
    }

    public static function workerSleepSeconds(): int
    {
        return (int) config('queue-autoscale.workers.sleep_seconds', 3);
    }

    public static function shutdownTimeoutSeconds(): int
    {
        return (int) config('queue-autoscale.workers.shutdown_timeout_seconds', 30);
    }

    public static function healthCheckIntervalSeconds(): int
    {
        return (int) config('queue-autoscale.workers.health_check_interval_seconds', 10);
    }

    public static function strategyClass(): string
    {
        return (string) config('queue-autoscale.strategy');
    }

    /** @return array<int, class-string> */
    public static function policyClasses(): array
    {
        return (array) config('queue-autoscale.policies', []);
    }
}
