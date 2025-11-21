<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Scaling\Strategies;

use PHPeek\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use PHPeek\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use PHPeek\LaravelQueueAutoscale\Configuration\TrendScalingPolicy;
use PHPeek\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;
use PHPeek\LaravelQueueAutoscale\Scaling\Calculators\BacklogDrainCalculator;
use PHPeek\LaravelQueueAutoscale\Scaling\Calculators\LittlesLawCalculator;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;

final class PredictiveStrategy implements ScalingStrategyContract
{
    /**
     * @var array<string, float|int|string>
     */
    private array $lastCalculation = [];

    private bool $usedFallback = false;

    public function __construct(
        private readonly LittlesLawCalculator $littles,
        private readonly BacklogDrainCalculator $backlog,
    ) {}

    public function calculateTargetWorkers(QueueMetricsData $metrics, QueueConfiguration $config): int
    {
        // Convert throughput_per_minute to jobs/second (processing rate)
        $processingRate = $metrics->throughputPerMinute / 60.0;

        $backlogSize = $metrics->pending;
        $oldestJobAge = $metrics->oldestJobAge;
        $activeWorkers = $metrics->activeWorkers;

        // Reset fallback flag
        $this->usedFallback = false;

        // Use p99 duration for worst-case protection, fall back to average, then estimate
        [$avgJobTime, $jobTimeSource] = $this->determineJobTime($metrics, $processingRate, $activeWorkers);

        // FALLBACK: If throughput is 0, estimate processing rate from available indicators
        if ($processingRate === 0.0) {
            $processingRate = $this->estimateFallbackProcessingRate(
                $backlogSize,
                $activeWorkers,
                $oldestJobAge,
                $avgJobTime,
                $config->maxPickupTimeSeconds
            );

            if ($processingRate > 0.0) {
                $this->usedFallback = true;
            }
        }

        // 1. RATE-BASED: Little's Law (L = λW)
        $steadyStateWorkers = $this->littles->calculate($processingRate, $avgJobTime);

        // 2. TREND-BASED: Predict future arrival rate using policy
        $trendPolicy = AutoscaleConfiguration::trendScalingPolicy();
        $trendData = $this->extractTrendData($metrics);

        $predictedRate = $this->applyTrendPolicy(
            $processingRate,
            $trendData,
            $trendPolicy
        );

        $predictiveWorkers = $this->littles->calculate($predictedRate, $avgJobTime);

        // 3. BACKLOG-BASED: Prevent SLA breach
        $backlogDrainWorkers = $this->backlog->calculateRequiredWorkers(
            backlog: $backlogSize,
            oldestJobAge: $oldestJobAge,
            slaTarget: $config->maxPickupTimeSeconds,
            avgJobTime: $avgJobTime,
            breachThreshold: AutoscaleConfiguration::breachThreshold(),
        );

        // 4. COMBINE: Take maximum (most conservative)
        $targetWorkers = max(
            $steadyStateWorkers,
            $predictiveWorkers,
            $backlogDrainWorkers,
        );

        // Store for reason building
        $this->lastCalculation = [
            'steady_state' => $steadyStateWorkers,
            'predictive' => $predictiveWorkers,
            'backlog_drain' => $backlogDrainWorkers,
            'predicted_rate' => $predictedRate,
            'avg_job_time' => $avgJobTime,
            'avg_job_time_source' => $jobTimeSource,
            'backlog' => $backlogSize,
            'processing_rate' => $processingRate,
        ];

        return (int) ceil(max($targetWorkers, 0));
    }

    public function getLastReason(): string
    {
        if (empty($this->lastCalculation)) {
            return 'No calculation performed yet';
        }

        $parts = [];
        $calc = $this->lastCalculation;

        if ($calc['backlog_drain'] > 0) {
            $parts[] = sprintf(
                'backlog=%d requires %.1f workers to prevent SLA breach',
                $calc['backlog'],
                $calc['backlog_drain']
            );
        }

        if ($calc['predictive'] > $calc['steady_state']) {
            $parts[] = sprintf(
                'trend predicts rate increase to %.2f/s requiring %.1f workers',
                $calc['predicted_rate'],
                $calc['predictive']
            );
        }

        if (empty($parts)) {
            $fallbackIndicator = $this->usedFallback ? ' (estimated)' : '';
            $jobTimeSource = ($calc['avg_job_time_source'] ?? 'unknown');
            $parts[] = sprintf(
                'steady state: rate=%.2f/s × time=%.1fs (%s) = %.1f workers%s',
                $calc['processing_rate'],
                $calc['avg_job_time'],
                $jobTimeSource,
                $calc['steady_state'],
                $fallbackIndicator
            );
        }

        return implode('; ', $parts);
    }

    public function getLastPrediction(): ?float
    {
        if (empty($this->lastCalculation)) {
            return null;
        }

        $calc = $this->lastCalculation;

        // Estimate pickup time based on backlog and workers
        $targetWorkers = max(
            $calc['steady_state'],
            $calc['predictive'],
            $calc['backlog_drain'],
        );

        if ($targetWorkers <= 0 || $calc['backlog'] === 0) {
            return 0.0;
        }

        // Time to process backlog with target workers
        $jobsPerWorker = (float) $calc['backlog'] / (float) $targetWorkers;
        $timeToProcess = $jobsPerWorker * (float) $calc['avg_job_time'];

        return $timeToProcess;
    }

    /**
     * Determine job time using available metrics
     *
     * Priority order:
     * 1. Average duration from metrics (when available)
     * 2. Estimation from throughput and worker count
     * 3. Fallback to 1 second default
     *
     * Note: P99, baseline, and trend data are available in the metrics package
     * but not currently exposed through the QueueMetricsData DTO.
     * These will be integrated when the DTO structure is extended.
     *
     * @return array{float, string} [avgJobTime, source]
     */
    private function determineJobTime(
        QueueMetricsData $metrics,
        float $processingRate,
        int $activeWorkers
    ): array {
        // Priority 1: Use average duration from metrics
        // Note: Metrics package stores avgDuration in milliseconds, convert to seconds
        if ($metrics->avgDuration > 0) {
            $avgDurationSeconds = $metrics->avgDuration / 1000.0;

            // Sanity check: If avgDuration is unreasonably low (< 10ms), it's likely a bug
            // Jobs that take < 10ms are extremely rare and would indicate a measurement issue
            if ($avgDurationSeconds < 0.01) {
                // Fall back to estimation
                [$estimated, $source] = $this->estimateAvgJobTime($processingRate, $activeWorkers);

                return [$estimated, sprintf('metrics.avgDuration too low (%.1fms), using %s', $metrics->avgDuration, $source)];
            }

            return [$avgDurationSeconds, sprintf('metrics.avgDuration: %.1fms → %.2fs', $metrics->avgDuration, $avgDurationSeconds)];
        }

        // Priority 2: Estimate from throughput and active workers
        return $this->estimateAvgJobTime($processingRate, $activeWorkers);
    }

    /**
     * Estimate average job time from throughput and workers
     *
     * Priority order:
     * 1. Throughput-based estimation (when active workers and rate available)
     * 2. Fallback: 1.0 second default
     *
     * Note: Baseline data integration will be added when DTO structure supports it
     *
     * @return array{float, string} [avgJobTime, source]
     */
    private function estimateAvgJobTime(float $processingRate, int $activeWorkers): array
    {
        // If we have active workers and processing rate, estimate from throughput
        if ($activeWorkers > 0 && $processingRate > 0) {
            $estimated = $activeWorkers / $processingRate;

            return [$estimated, sprintf('estimated: %d workers / %.2f rate', $activeWorkers, $processingRate)];
        }

        // Last resort: assume 1 second per job
        return [1.0, 'fallback: 1.0s default'];
    }

    /**
     * Extract trend data from queue metrics
     *
     * Note: Trend data is available in the metrics package but not currently
     * exposed through the QueueMetricsData DTO. Returns null until DTO supports it.
     */
    private function extractTrendData(QueueMetricsData $metrics): null
    {
        // Trend data not currently accessible through DTO
        // Will be integrated when laravel-queue-metrics exposes it properly
        return null;
    }

    /**
     * Apply trend policy to determine predicted processing rate
     *
     * @param  array<string, mixed>|null  $trendData
     */
    private function applyTrendPolicy(
        float $currentRate,
        ?array $trendData,
        TrendScalingPolicy $policy
    ): float {
        // If policy is disabled or no trend data, use current rate
        if (! $policy->isEnabled() || $trendData === null) {
            return $currentRate;
        }

        // Check if trend confidence meets minimum threshold
        if ($trendData['confidence'] < $policy->minConfidence()) {
            return $currentRate;
        }

        // Blend current rate with forecast based on policy weight
        $trendWeight = $policy->trendWeight();
        $currentWeight = 1.0 - $trendWeight;

        // Forecast is in jobs/minute, convert to jobs/second
        $forecast = (float) ($trendData['forecast'] ?? 0.0);
        $forecastRate = $forecast / 60.0;

        // Weighted blend
        $predictedRate = ($currentRate * $currentWeight) + ($forecastRate * $trendWeight);

        // Never predict negative rate
        return max($predictedRate, 0.0);
    }

    /**
     * Estimate processing rate when throughput data is unavailable
     *
     * Uses multiple indicators to make intelligent estimates:
     * 1. Active workers' capacity (if workers exist, they're processing)
     * 2. Backlog demand (if backlog exists, we need workers)
     * 3. Urgency from oldest job age (how fast we need to process)
     *
     * This prevents the "death spiral" where zero throughput leads to
     * scaling down to zero workers despite having pending jobs.
     *
     * @param  int  $backlogSize  Number of pending jobs
     * @param  int  $activeWorkers  Current active workers
     * @param  int  $oldestJobAge  Age of oldest job in seconds
     * @param  float  $avgJobTime  Average processing time per job
     * @param  int  $slaTarget  Max pickup time SLA in seconds
     * @return float Estimated processing rate in jobs/second
     */
    private function estimateFallbackProcessingRate(
        int $backlogSize,
        int $activeWorkers,
        int $oldestJobAge,
        float $avgJobTime,
        int $slaTarget,
    ): float {
        // Strategy 1: Estimate from active workers' capacity
        // If we have workers, they're likely processing at their natural rate
        if ($activeWorkers > 0 && $avgJobTime > 0) {
            // Each worker can process 1/avgJobTime jobs per second
            $workerCapacity = $activeWorkers / $avgJobTime;

            // Conservative: assume 70% utilization (workers aren't always at 100%)
            return $workerCapacity * 0.7;
        }

        // Strategy 2: Estimate from backlog demand
        // If we have backlog but no workers, estimate minimum needed rate
        if ($backlogSize > 0 && $slaTarget > 0) {
            // If we have oldest job age, use it for urgency
            if ($oldestJobAge > 0) {
                // We need to process faster if jobs are getting old
                $urgencyFactor = min($oldestJobAge / max($slaTarget * 0.5, 1), 2.0);

                // Base rate: process backlog within SLA window
                $baseRate = $backlogSize / max($slaTarget, 1);

                // Apply urgency multiplier (up to 2x)
                return $baseRate * $urgencyFactor;
            }

            // No age data: conservative estimate to process backlog within SLA
            // Aim to clear backlog in half the SLA time for safety margin
            return $backlogSize / max($slaTarget / 2, 1);
        }

        // Strategy 3: No workers and no backlog
        // This is idle state - no throughput estimate needed
        return 0.0;
    }
}
