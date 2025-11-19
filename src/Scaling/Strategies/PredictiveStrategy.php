<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Scaling\Strategies;

use PHPeek\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use PHPeek\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use PHPeek\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;
use PHPeek\LaravelQueueAutoscale\Scaling\Calculators\BacklogDrainCalculator;
use PHPeek\LaravelQueueAutoscale\Scaling\Calculators\LittlesLawCalculator;
use PHPeek\LaravelQueueAutoscale\Scaling\Calculators\TrendPredictor;

final class PredictiveStrategy implements ScalingStrategyContract
{
    private array $lastCalculation = [];

    public function __construct(
        private readonly LittlesLawCalculator $littles,
        private readonly TrendPredictor $trends,
        private readonly BacklogDrainCalculator $backlog,
    ) {}

    public function calculateTargetWorkers(object $metrics, QueueConfiguration $config): int
    {
        // Extract metrics from QueueMetricsData object
        $processingRate = $metrics->processingRate ?? 0.0;
        $backlogSize = $metrics->depth->pending ?? 0;
        $oldestJobAge = $metrics->depth->oldestJobAgeSeconds ?? 0;
        $activeWorkers = $metrics->activeWorkerCount ?? 0;

        // Estimate average job processing time
        $avgJobTime = $this->estimateAvgJobTime($processingRate, $activeWorkers);

        // 1. RATE-BASED: Little's Law (L = λW)
        $steadyStateWorkers = $this->littles->calculate($processingRate, $avgJobTime);

        // 2. TREND-BASED: Predict future arrival rate
        $trend = $metrics->trend ?? null;
        $predictedRate = $this->trends->predictArrivalRate(
            $processingRate,
            $trend,
            AutoscaleConfiguration::forecastHorizonSeconds(),
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
            $parts[] = sprintf(
                'steady state: rate=%.2f/s × time=%.1fs = %.1f workers',
                $calc['processing_rate'],
                $calc['avg_job_time'],
                $calc['steady_state']
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
        $jobsPerWorker = $calc['backlog'] / $targetWorkers;
        $timeToProcess = $jobsPerWorker * $calc['avg_job_time'];

        return $timeToProcess;
    }

    private function estimateAvgJobTime(float $processingRate, int $activeWorkers): float
    {
        // If we have active workers and processing rate, estimate from throughput
        if ($activeWorkers > 0 && $processingRate > 0) {
            return $activeWorkers / $processingRate;
        }

        // Fallback: assume 1 second per job
        return 1.0;
    }
}
