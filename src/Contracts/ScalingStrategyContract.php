<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Contracts;

use PHPeek\LaravelQueueAutoscale\Configuration\QueueConfiguration;

interface ScalingStrategyContract
{
    /**
     * Calculate target number of workers for a queue
     *
     * @param object $metrics QueueMetricsData from laravel-queue-metrics
     * @param QueueConfiguration $config Queue SLA configuration
     * @return int Target worker count
     */
    public function calculateTargetWorkers(object $metrics, QueueConfiguration $config): int;

    /**
     * Get human-readable reason for last scaling decision
     */
    public function getLastReason(): string;

    /**
     * Get predicted pickup time (seconds) from last calculation
     */
    public function getLastPrediction(): ?float;
}
