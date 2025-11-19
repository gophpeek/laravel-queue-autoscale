<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Scaling\Calculators;

use PHPeek\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use PHPeek\SystemMetrics\SystemMetrics;

final readonly class CapacityCalculator
{
    /**
     * Calculate maximum workers that can be spawned without exceeding resource limits
     *
     * Considers both CPU and memory constraints, returns the most restrictive.
     *
     * @return int Maximum workers possible given current system capacity
     */
    public function calculateMaxWorkers(): int
    {
        $limitsResult = SystemMetrics::limits();
        if ($limitsResult->isFailure()) {
            // Fallback: if can't get limits, allow conservative amount
            return 5;
        }

        $limits = $limitsResult->getValue();

        // CPU capacity
        $maxCpuPercent = AutoscaleConfiguration::maxCpuPercent();
        $cpuUsageResult = SystemMetrics::cpuUsage(1.0);

        $currentCpuPercent = $cpuUsageResult->isSuccess()
            ? $cpuUsageResult->getValue()->usagePercentage()
            : 50.0;

        $availableCpuPercent = max($maxCpuPercent - $currentCpuPercent, 0);
        $reserveCores = AutoscaleConfiguration::reserveCpuCores();
        $usableCores = max($limits->availableCpuCores() - $reserveCores, 1);

        $maxWorkersByCpu = (int) floor($usableCores * ($availableCpuPercent / 100));

        // Memory capacity
        $maxMemoryPercent = AutoscaleConfiguration::maxMemoryPercent();
        $memoryResult = SystemMetrics::memory();

        $currentMemoryPercent = $memoryResult->isSuccess()
            ? $memoryResult->getValue()->usedPercentage()
            : 50.0;

        $availableMemoryPercent = max($maxMemoryPercent - $currentMemoryPercent, 0);
        $workerMemoryMb = AutoscaleConfiguration::workerMemoryMbEstimate();
        $totalMemoryMb = $limits->availableMemoryBytes() / (1024 * 1024);

        $maxWorkersByMemory = (int) floor(
            ($totalMemoryMb * ($availableMemoryPercent / 100)) / $workerMemoryMb
        );

        // Return most restrictive
        return max(min($maxWorkersByCpu, $maxWorkersByMemory), 0);
    }
}
