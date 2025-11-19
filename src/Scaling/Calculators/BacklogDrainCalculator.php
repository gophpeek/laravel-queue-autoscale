<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Scaling\Calculators;

final readonly class BacklogDrainCalculator
{
    /**
     * Calculate workers needed to drain backlog before SLA breach
     *
     * Acts proactively at breach threshold (e.g., 80% of SLA time)
     *
     * @param  int  $backlog  Number of pending jobs
     * @param  int  $oldestJobAge  Age of oldest job in seconds
     * @param  int  $slaTarget  Max pickup time SLA in seconds
     * @param  float  $avgJobTime  Average processing time per job in seconds
     * @param  float  $breachThreshold  Threshold (0-1) to trigger action
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

        // Time until SLA breach
        $timeUntilBreach = $slaTarget - $oldestJobAge;

        // Act proactively at threshold (e.g., 80% of SLA = 24s of 30s)
        $actionThreshold = (int) ($slaTarget * $breachThreshold);

        if ($oldestJobAge < $actionThreshold) {
            return 0.0; // No urgent action needed
        }

        // Already breached! Scale up aggressively
        if ($timeUntilBreach <= 0) {
            return (float) ceil($backlog / max($avgJobTime, 0.1));
        }

        // Workers needed = Backlog / (Time remaining / Avg job time)
        $jobsPerWorker = max($timeUntilBreach / $avgJobTime, 1.0);

        return $backlog / $jobsPerWorker;
    }
}
