<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Policies;

use PHPeek\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use PHPeek\LaravelQueueAutoscale\Scaling\ScalingDecision;

/**
 * Policy that prevents scaling down workers
 *
 * Use Case: Critical/mission-critical workloads where maintaining capacity is more important than cost.
 * This policy ensures workers are never removed, keeping the queue always ready to process jobs.
 *
 * Example:
 * - Payment processing systems
 * - Order fulfillment queues
 * - Real-time notification systems
 *
 * Trade-offs:
 * - Higher cost (workers stay running even when idle)
 * - Excellent reliability (zero cold start delays)
 * - Predictable performance
 */
final readonly class NoScaleDownPolicy implements ScalingPolicy
{
    public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
    {
        // If decision is to scale down, prevent it by setting target to current
        if ($decision->shouldScaleDown()) {
            return new ScalingDecision(
                connection: $decision->connection,
                queue: $decision->queue,
                currentWorkers: $decision->currentWorkers,
                targetWorkers: $decision->currentWorkers, // Prevent scale-down
                reason: 'NoScaleDownPolicy prevented scale-down - maintaining capacity',
                predictedPickupTime: $decision->predictedPickupTime,
                slaTarget: $decision->slaTarget,
            );
        }

        // Allow scale-up and hold decisions to pass through
        return null;
    }

    public function afterScaling(ScalingDecision $decision): void
    {
        // No action needed after scaling
    }
}
