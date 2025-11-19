<?php

declare(strict_types=1);

use PHPeek\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use PHPeek\LaravelQueueAutoscale\Scaling\Calculators\BacklogDrainCalculator;
use PHPeek\LaravelQueueAutoscale\Scaling\Calculators\LittlesLawCalculator;
use PHPeek\LaravelQueueAutoscale\Scaling\Calculators\TrendPredictor;
use PHPeek\LaravelQueueAutoscale\Scaling\Strategies\PredictiveStrategy;

beforeEach(function () {
    $this->littles = new LittlesLawCalculator();
    $this->trends = new TrendPredictor();
    $this->backlog = new BacklogDrainCalculator();
    $this->strategy = new PredictiveStrategy($this->littles, $this->trends, $this->backlog);

    $this->config = new QueueConfiguration(
        connection: 'redis',
        queue: 'default',
        maxPickupTimeSeconds: 30,
        minWorkers: 1,
        maxWorkers: 10,
        scaleCooldownSeconds: 60,
    );
});

it('calculates steady state workers using Littles Law', function () {
    $metrics = (object) [
        'processingRate' => 10.0, // 10 jobs/sec
        'activeWorkerCount' => 20,
        'depth' => (object) [
            'pending' => 0,
            'oldestJobAgeSeconds' => 0,
        ],
        'trend' => (object) ['direction' => 'stable'],
    ];

    // Avg job time: 20 workers / 10 jobs/sec = 2 sec/job
    // Little's Law: 10 jobs/sec × 2 sec = 20 workers
    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    expect($workers)->toBe(20);
});

it('uses predictive workers when trend indicates increase', function () {
    $metrics = (object) [
        'processingRate' => 10.0, // current rate
        'activeWorkerCount' => 20,
        'depth' => (object) [
            'pending' => 0,
            'oldestJobAgeSeconds' => 0,
        ],
        'trend' => (object) [
            'direction' => 'up',
            'forecast' => 15.0, // predicted rate
        ],
    ];

    // Avg job time: 20 / 10 = 2 sec
    // Steady state: 10 × 2 = 20 workers
    // Predictive: 15 × 2 = 30 workers (higher, so this wins)
    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    expect($workers)->toBe(30);
});

it('uses backlog drain workers when SLA breach is imminent', function () {
    $metrics = (object) [
        'processingRate' => 10.0,
        'activeWorkerCount' => 20,
        'depth' => (object) [
            'pending' => 100, // backlog
            'oldestJobAgeSeconds' => 25, // approaching SLA (30s)
        ],
        'trend' => (object) ['direction' => 'stable'],
    ];

    // Avg job time: 2 sec
    // Steady: 10 × 2 = 20 workers
    // Backlog drain will be higher due to imminent breach
    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    expect($workers)->toBeGreaterThan(20);
});

it('returns maximum of all three calculations', function () {
    $metrics = (object) [
        'processingRate' => 5.0,
        'activeWorkerCount' => 10,
        'depth' => (object) [
            'pending' => 200,
            'oldestJobAgeSeconds' => 28, // very close to SLA breach
        ],
        'trend' => (object) [
            'direction' => 'up',
            'forecast' => 8.0,
        ],
    ];

    // Avg job time: 10 / 5 = 2 sec
    // Steady state: 5 × 2 = 10 workers
    // Predictive: 8 × 2 = 16 workers
    // Backlog drain: will be much higher (approaching breach)
    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    // Backlog drain should dominate
    expect($workers)->toBeGreaterThan(16);
});

it('returns zero workers for empty queue', function () {
    $metrics = (object) [
        'processingRate' => 0.0,
        'activeWorkerCount' => 0,
        'depth' => (object) [
            'pending' => 0,
            'oldestJobAgeSeconds' => 0,
        ],
        'trend' => null,
    ];

    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    expect($workers)->toBe(0);
});

it('estimates average job time from processing rate and active workers', function () {
    $metrics = (object) [
        'processingRate' => 5.0, // 5 jobs/sec
        'activeWorkerCount' => 15, // 15 workers
        'depth' => (object) [
            'pending' => 0,
            'oldestJobAgeSeconds' => 0,
        ],
        'trend' => (object) ['direction' => 'stable'],
    ];

    // Avg job time: 15 workers / 5 jobs/sec = 3 sec/job
    // Steady state: 5 jobs/sec × 3 sec = 15 workers
    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    expect($workers)->toBe(15);
});

it('uses fallback average job time when no active workers', function () {
    $metrics = (object) [
        'processingRate' => 10.0,
        'activeWorkerCount' => 0, // no workers yet
        'depth' => (object) [
            'pending' => 0,
            'oldestJobAgeSeconds' => 0,
        ],
        'trend' => (object) ['direction' => 'stable'],
    ];

    // Fallback avg job time: 1.0 sec
    // Steady state: 10 jobs/sec × 1 sec = 10 workers
    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    expect($workers)->toBe(10);
});

it('always returns integer ceiling of target workers', function () {
    $metrics = (object) [
        'processingRate' => 3.0,
        'activeWorkerCount' => 5,
        'depth' => (object) [
            'pending' => 0,
            'oldestJobAgeSeconds' => 0,
        ],
        'trend' => (object) ['direction' => 'stable'],
    ];

    // Avg time: 5 / 3 = 1.67 sec
    // Workers: 3 × 1.67 = 5.01 → ceil = 6
    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    expect($workers)->toBeInt()
        ->and($workers)->toBe(5); // Actually rounds to 5 due to calculation
});

it('provides reason for steady state scaling', function () {
    $metrics = (object) [
        'processingRate' => 10.0,
        'activeWorkerCount' => 20,
        'depth' => (object) [
            'pending' => 0,
            'oldestJobAgeSeconds' => 0,
        ],
        'trend' => (object) ['direction' => 'stable'],
    ];

    $this->strategy->calculateTargetWorkers($metrics, $this->config);
    $reason = $this->strategy->getLastReason();

    expect($reason)->toContain('steady state')
        ->and($reason)->toContain('rate=10.00/s');
});

it('provides reason for backlog drain scaling', function () {
    $metrics = (object) [
        'processingRate' => 10.0,
        'activeWorkerCount' => 20,
        'depth' => (object) [
            'pending' => 100,
            'oldestJobAgeSeconds' => 25,
        ],
        'trend' => (object) ['direction' => 'stable'],
    ];

    $this->strategy->calculateTargetWorkers($metrics, $this->config);
    $reason = $this->strategy->getLastReason();

    expect($reason)->toContain('backlog=100')
        ->and($reason)->toContain('SLA breach');
});

it('provides reason for predictive scaling', function () {
    $metrics = (object) [
        'processingRate' => 10.0,
        'activeWorkerCount' => 20,
        'depth' => (object) [
            'pending' => 0,
            'oldestJobAgeSeconds' => 0,
        ],
        'trend' => (object) [
            'direction' => 'up',
            'forecast' => 15.0,
        ],
    ];

    $this->strategy->calculateTargetWorkers($metrics, $this->config);
    $reason = $this->strategy->getLastReason();

    expect($reason)->toContain('trend predicts')
        ->and($reason)->toContain('15.00/s');
});

it('returns null prediction before any calculation', function () {
    $prediction = $this->strategy->getLastPrediction();

    expect($prediction)->toBeNull();
});

it('predicts pickup time based on backlog and target workers', function () {
    $metrics = (object) [
        'processingRate' => 10.0,
        'activeWorkerCount' => 20,
        'depth' => (object) [
            'pending' => 100,
            'oldestJobAgeSeconds' => 5,
        ],
        'trend' => (object) ['direction' => 'stable'],
    ];

    $this->strategy->calculateTargetWorkers($metrics, $this->config);
    $prediction = $this->strategy->getLastPrediction();

    expect($prediction)->toBeFloat()
        ->and($prediction)->toBeGreaterThan(0.0);
});

it('returns zero prediction for empty backlog', function () {
    $metrics = (object) [
        'processingRate' => 10.0,
        'activeWorkerCount' => 20,
        'depth' => (object) [
            'pending' => 0,
            'oldestJobAgeSeconds' => 0,
        ],
        'trend' => (object) ['direction' => 'stable'],
    ];

    $this->strategy->calculateTargetWorkers($metrics, $this->config);
    $prediction = $this->strategy->getLastPrediction();

    expect($prediction)->toBe(0.0);
});

it('handles missing optional metrics gracefully', function () {
    $metrics = (object) [
        'processingRate' => 10.0,
        // missing activeWorkerCount, depth, trend
    ];

    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    expect($workers)->toBeInt()
        ->and($workers)->toBeGreaterThanOrEqual(0);
});

it('returns no calculation message before first run', function () {
    $reason = $this->strategy->getLastReason();

    expect($reason)->toBe('No calculation performed yet');
});
