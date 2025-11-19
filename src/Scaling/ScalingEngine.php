<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Scaling;

use PHPeek\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use PHPeek\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;
use PHPeek\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;

final readonly class ScalingEngine
{
    public function __construct(
        private ScalingStrategyContract $strategy,
        private CapacityCalculator $capacity,
    ) {}

    /**
     * Evaluate scaling decision for a queue
     *
     * @param object $metrics QueueMetricsData from laravel-queue-metrics
     * @param QueueConfiguration $config Queue SLA configuration
     * @param int $currentWorkers Current worker count for this queue
     * @return ScalingDecision Scaling decision with target workers
     */
    public function evaluate(
        object $metrics,
        QueueConfiguration $config,
        int $currentWorkers,
    ): ScalingDecision {
        // 1. Calculate target workers based on strategy
        $targetWorkers = $this->strategy->calculateTargetWorkers($metrics, $config);

        // 2. Apply resource constraints (system capacity)
        $maxPossible = $this->capacity->calculateMaxWorkers();
        $targetWorkers = min($targetWorkers, $maxPossible);

        // 3. Apply config bounds (min/max workers)
        $targetWorkers = max($targetWorkers, $config->minWorkers);
        $targetWorkers = min($targetWorkers, $config->maxWorkers);

        return new ScalingDecision(
            connection: $config->connection,
            queue: $config->queue,
            currentWorkers: $currentWorkers,
            targetWorkers: $targetWorkers,
            reason: $this->strategy->getLastReason(),
            predictedPickupTime: $this->strategy->getLastPrediction(),
            slaTarget: $config->maxPickupTimeSeconds,
        );
    }
}
