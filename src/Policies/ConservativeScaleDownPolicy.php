<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Policies;

use PHPeek\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use PHPeek\LaravelQueueAutoscale\Scaling\ScalingDecision;

/**
 * Policy that limits scale-down to a maximum of 1 worker per evaluation cycle
 *
 * Use Case: Workloads that benefit from gradual scaling to avoid oscillation and thrashing.
 * This policy prevents aggressive scale-down that could cause rapid up/down cycles.
 *
 * Example:
 * - High-volume email sending
 * - Batch processing with variable load
 * - General web application queues
 *
 * Benefits:
 * - Prevents scaling thrashing (rapid up/down cycles)
 * - Smoother resource utilization
 * - More predictable behavior
 * - Better for workloads with variable but persistent demand
 *
 * Trade-offs:
 * - Slower cost reduction when load drops
 * - May maintain excess workers longer than needed
 */
final readonly class ConservativeScaleDownPolicy implements ScalingPolicy
{
    public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
    {
        // Only modify scale-down decisions
        if (! $decision->shouldScaleDown()) {
            return null;
        }

        $workersToRemove = $decision->workersToRemove();

        // If removing more than 1 worker, limit to 1
        if ($workersToRemove > 1) {
            $conservativeTarget = $decision->currentWorkers - 1;

            return new ScalingDecision(
                connection: $decision->connection,
                queue: $decision->queue,
                currentWorkers: $decision->currentWorkers,
                targetWorkers: $conservativeTarget,
                reason: sprintf(
                    'ConservativeScaleDownPolicy limited scale-down from %d to 1 worker (original: %s)',
                    $workersToRemove,
                    $decision->reason
                ),
                predictedPickupTime: $decision->predictedPickupTime,
                slaTarget: $decision->slaTarget,
            );
        }

        // Already removing 1 or 0 workers, allow it
        return null;
    }

    public function afterScaling(ScalingDecision $decision): void
    {
        // No action needed after scaling
    }
}
