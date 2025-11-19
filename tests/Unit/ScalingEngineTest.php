<?php

declare(strict_types=1);

use PHPeek\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use PHPeek\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;
use PHPeek\LaravelQueueAutoscale\Scaling\Calculators\BacklogDrainCalculator;
use PHPeek\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use PHPeek\LaravelQueueAutoscale\Scaling\Calculators\LittlesLawCalculator;
use PHPeek\LaravelQueueAutoscale\Scaling\Calculators\TrendPredictor;
use PHPeek\LaravelQueueAutoscale\Scaling\ScalingEngine;
use PHPeek\LaravelQueueAutoscale\Scaling\Strategies\PredictiveStrategy;

beforeEach(function () {
    $this->strategy = new PredictiveStrategy(
        new LittlesLawCalculator(),
        new TrendPredictor(),
        new BacklogDrainCalculator(),
    );
    $this->capacity = new CapacityCalculator();
    $this->engine = new ScalingEngine($this->strategy, $this->capacity);

    $this->config = new QueueConfiguration(
        connection: 'redis',
        queue: 'default',
        maxPickupTimeSeconds: 30,
        minWorkers: 1,
        maxWorkers: 10,
        scaleCooldownSeconds: 60,
    );

    $this->metrics = (object) [
        'processingRate' => 5.0,
        'activeWorkerCount' => 10,
        'depth' => (object) [
            'pending' => 0,
            'oldestJobAgeSeconds' => 0,
        ],
        'trend' => (object) ['direction' => 'stable'],
    ];
});

it('evaluates scaling decision using strategy', function () {
    $decision = $this->engine->evaluate($this->metrics, $this->config, 5);

    expect($decision)->toBeInstanceOf(\PHPeek\LaravelQueueAutoscale\Scaling\ScalingDecision::class)
        ->and($decision->connection)->toBe('redis')
        ->and($decision->queue)->toBe('default')
        ->and($decision->currentWorkers)->toBe(5)
        ->and($decision->targetWorkers)->toBeInt();
});

it('enforces minimum workers constraint', function () {
    // Create metrics that would result in 0 workers
    $emptyMetrics = (object) [
        'processingRate' => 0.0,
        'activeWorkerCount' => 0,
        'depth' => (object) [
            'pending' => 0,
            'oldestJobAgeSeconds' => 0,
        ],
        'trend' => null,
    ];

    $decision = $this->engine->evaluate($emptyMetrics, $this->config, 0);

    // Should be at least minWorkers (1)
    expect($decision->targetWorkers)->toBeGreaterThanOrEqual($this->config->minWorkers);
});

it('enforces maximum workers constraint', function () {
    // Create metrics that would result in very high worker count
    $highLoadMetrics = (object) [
        'processingRate' => 100.0,
        'activeWorkerCount' => 200,
        'depth' => (object) [
            'pending' => 1000,
            'oldestJobAgeSeconds' => 28, // near SLA breach
        ],
        'trend' => (object) [
            'direction' => 'up',
            'forecast' => 150.0,
        ],
    ];

    $decision = $this->engine->evaluate($highLoadMetrics, $this->config, 5);

    // Should not exceed maxWorkers (10)
    expect($decision->targetWorkers)->toBeLessThanOrEqual($this->config->maxWorkers);
});

it('applies capacity constraints from system resources', function () {
    // Create config with very high max workers
    $highConfig = new QueueConfiguration(
        connection: 'redis',
        queue: 'default',
        maxPickupTimeSeconds: 30,
        minWorkers: 1,
        maxWorkers: 1000, // unrealistic max
        scaleCooldownSeconds: 60,
    );

    $highLoadMetrics = (object) [
        'processingRate' => 100.0,
        'activeWorkerCount' => 200,
        'depth' => (object) [
            'pending' => 1000,
            'oldestJobAgeSeconds' => 0,
        ],
        'trend' => (object) ['direction' => 'stable'],
    ];

    $decision = $this->engine->evaluate($highLoadMetrics, $highConfig, 5);

    // Should be constrained by system capacity (much less than 1000)
    expect($decision->targetWorkers)->toBeLessThan(1000);
});

it('includes reason from strategy in decision', function () {
    $decision = $this->engine->evaluate($this->metrics, $this->config, 5);

    expect($decision->reason)->toBeString()
        ->and($decision->reason)->not->toBeEmpty();
});

it('includes predicted pickup time from strategy in decision', function () {
    $metricsWithBacklog = (object) [
        'processingRate' => 10.0,
        'activeWorkerCount' => 20,
        'depth' => (object) [
            'pending' => 50,
            'oldestJobAgeSeconds' => 5,
        ],
        'trend' => (object) ['direction' => 'stable'],
    ];

    $decision = $this->engine->evaluate($metricsWithBacklog, $this->config, 10);

    expect($decision->predictedPickupTime)->toBeFloat();
});

it('includes SLA target in decision', function () {
    $decision = $this->engine->evaluate($this->metrics, $this->config, 5);

    expect($decision->slaTarget)->toBe(30);
});

it('handles strategy returning fractional workers', function () {
    // Metrics that result in fractional worker count
    $metrics = (object) [
        'processingRate' => 3.5,
        'activeWorkerCount' => 7,
        'depth' => (object) [
            'pending' => 0,
            'oldestJobAgeSeconds' => 0,
        ],
        'trend' => (object) ['direction' => 'stable'],
    ];

    $decision = $this->engine->evaluate($metrics, $this->config, 3);

    // Target workers should be integer
    expect($decision->targetWorkers)->toBeInt();
});

it('respects strategy recommendation within bounds', function () {
    // Create scenario where strategy recommends 5 workers
    // Config allows 1-10, capacity should allow 5
    // Calculation: rate=5, workers=5 → avg_time=1s → steady=5×1=5
    $metrics = (object) [
        'processingRate' => 5.0,
        'activeWorkerCount' => 5,  // Changed from 10 to 5
        'depth' => (object) [
            'pending' => 0,
            'oldestJobAgeSeconds' => 0,
        ],
        'trend' => (object) ['direction' => 'stable'],
    ];

    $decision = $this->engine->evaluate($metrics, $this->config, 5);

    // Should be within bounds (strategy recommends 5, but capacity may limit)
    expect($decision->targetWorkers)->toBeGreaterThanOrEqual($this->config->minWorkers)
        ->and($decision->targetWorkers)->toBeLessThanOrEqual($this->config->maxWorkers);
});

it('prioritizes min workers over capacity when capacity is very low', function () {
    // Note: This is theoretical since we can't easily force low capacity
    // The engine should still enforce minWorkers even if capacity says 0

    $emptyMetrics = (object) [
        'processingRate' => 0.0,
        'activeWorkerCount' => 0,
        'depth' => (object) [
            'pending' => 0,
            'oldestJobAgeSeconds' => 0,
        ],
        'trend' => null,
    ];

    $decision = $this->engine->evaluate($emptyMetrics, $this->config, 0);

    // Even with 0 demand, should maintain at least minWorkers
    expect($decision->targetWorkers)->toBe($this->config->minWorkers);
});

it('creates decision with all required fields', function () {
    $decision = $this->engine->evaluate($this->metrics, $this->config, 5);

    expect($decision)->toHaveProperty('connection')
        ->and($decision)->toHaveProperty('queue')
        ->and($decision)->toHaveProperty('currentWorkers')
        ->and($decision)->toHaveProperty('targetWorkers')
        ->and($decision)->toHaveProperty('reason')
        ->and($decision)->toHaveProperty('predictedPickupTime')
        ->and($decision)->toHaveProperty('slaTarget');
});

it('uses provided current workers count', function () {
    $decision = $this->engine->evaluate($this->metrics, $this->config, 15);

    expect($decision->currentWorkers)->toBe(15);
});

it('applies constraints in correct order', function () {
    // Test: strategy → capacity → config bounds
    // Create scenario where each constraint matters

    $config = new QueueConfiguration(
        connection: 'redis',
        queue: 'test',
        maxPickupTimeSeconds: 30,
        minWorkers: 2, // min constraint
        maxWorkers: 8, // max constraint
        scaleCooldownSeconds: 60,
    );

    $highMetrics = (object) [
        'processingRate' => 50.0,
        'activeWorkerCount' => 100,
        'depth' => (object) [
            'pending' => 500,
            'oldestJobAgeSeconds' => 25,
        ],
        'trend' => (object) [
            'direction' => 'up',
            'forecast' => 75.0,
        ],
    ];

    $decision = $this->engine->evaluate($highMetrics, $config, 5);

    // Should be capped at config maxWorkers
    expect($decision->targetWorkers)->toBeLessThanOrEqual($config->maxWorkers);
});
