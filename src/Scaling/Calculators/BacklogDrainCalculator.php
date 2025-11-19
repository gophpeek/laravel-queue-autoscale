<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Scaling\Calculators;

final readonly class BacklogDrainCalculator
{
    /**
     * Calculate workers needed to drain backlog before SLA breach
     *
     * Progressive aggressiveness approach:
     * - 50%-80% of SLA: Start preparing (1.2x multiplier)
     * - 80%-90% of SLA: Scale aggressively (1.5x multiplier)
     * - 90%-100% of SLA: Emergency scaling (2.0x multiplier)
     * - 100%+ of SLA: Maximum aggression (3.0x multiplier)
     *
     * @param  int  $backlog  Number of pending jobs
     * @param  int  $oldestJobAge  Age of oldest job in seconds
     * @param  int  $slaTarget  Max pickup time SLA in seconds
     * @param  float  $avgJobTime  Average processing time per job in seconds
     * @param  float  $breachThreshold  Threshold (0-1) to trigger action (typically 0.5 = 50%)
     * @return float Required workers (fractional, caller should ceil())
     */
    public function calculateRequiredWorkers(
        int $backlog,
        int $oldestJobAge,
        int $slaTarget,
        float $avgJobTime,
        float $breachThreshold,
    ): float {
        if ($backlog === 0 || $avgJobTime <= 0) {
            return 0.0;
        }

        // Fallback: If oldest job age is unavailable (0) but we have backlog,
        // assume we should start processing. Not all queue drivers can provide age data.
        if ($oldestJobAge === 0 && $backlog > 0) {
            // Use conservative estimate: process backlog within full SLA window
            $jobsPerWorker = max($slaTarget / $avgJobTime, 1.0);

            return $backlog / $jobsPerWorker;
        }

        // Calculate how far through SLA we are (as percentage)
        $slaProgress = min($oldestJobAge / $slaTarget, 1.5); // Cap at 150% for extreme cases

        // Act proactively at threshold (e.g., 50% of SLA)
        if ($slaProgress < $breachThreshold) {
            return 0.0; // No urgent action needed yet
        }

        // Time until SLA breach
        $timeUntilBreach = $slaTarget - $oldestJobAge;

        // Calculate base workers needed
        $baseWorkers = $timeUntilBreach > 0
            ? $backlog / max($timeUntilBreach / $avgJobTime, 1.0)
            : $backlog / max($avgJobTime, 0.1);

        // Apply progressive aggressiveness multiplier based on SLA progress
        $multiplier = $this->getAggressivenessMultiplier($slaProgress);

        return $baseWorkers * $multiplier;
    }

    /**
     * Get aggressiveness multiplier based on how close we are to SLA breach
     *
     * Progressive scaling to prevent breaches early:
     * - 0.0-0.5: No action (0.0x)
     * - 0.5-0.8: Early preparation (1.2x)
     * - 0.8-0.9: Aggressive scaling (1.5x)
     * - 0.9-1.0: Emergency scaling (2.0x)
     * - 1.0+: Already breached! (3.0x)
     */
    private function getAggressivenessMultiplier(float $slaProgress): float
    {
        return match (true) {
            $slaProgress >= 1.0 => 3.0,  // Already breached! Maximum aggression
            $slaProgress >= 0.9 => 2.0,  // 90%+ Emergency scaling
            $slaProgress >= 0.8 => 1.5,  // 80%+ Aggressive scaling
            $slaProgress >= 0.5 => 1.2,  // 50%+ Early preparation
            default => 0.0,              // Below threshold - no action
        };
    }
}
