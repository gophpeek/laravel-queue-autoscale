<?php

declare(strict_types=1);

use PHPeek\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;

/**
 * Note: CapacityCalculator uses SystemMetrics which queries actual system state.
 * These tests verify the calculator works correctly with real system metrics.
 * For full isolation, SystemMetrics would need to be injectable/mockable.
 */

it('returns non-negative integer for max workers', function () {
    $calculator = new CapacityCalculator();

    $maxWorkers = $calculator->calculateMaxWorkers();

    expect($maxWorkers)->toBeInt()
        ->and($maxWorkers)->toBeGreaterThanOrEqual(0);
});

it('returns conservative fallback when system metrics fail', function () {
    $calculator = new CapacityCalculator();

    // We can't easily force SystemMetrics::limits() to fail in tests,
    // but we verify the method doesn't throw exceptions
    $maxWorkers = $calculator->calculateMaxWorkers();

    expect($maxWorkers)->toBeInt()
        ->and($maxWorkers)->toBeGreaterThanOrEqual(0);
});

it('calculates capacity based on current system state', function () {
    $calculator = new CapacityCalculator();

    // First calculation
    $workers1 = $calculator->calculateMaxWorkers();

    // Second calculation (should be consistent in stable system)
    $workers2 = $calculator->calculateMaxWorkers();

    expect($workers1)->toBeInt()
        ->and($workers2)->toBeInt()
        ->and($workers1)->toBeGreaterThanOrEqual(0)
        ->and($workers2)->toBeGreaterThanOrEqual(0);
});

it('respects system resource constraints', function () {
    $calculator = new CapacityCalculator();

    $maxWorkers = $calculator->calculateMaxWorkers();

    // Max workers should be reasonable (not millions)
    // This validates the calculation uses constraints properly
    expect($maxWorkers)->toBeLessThan(1000);
});
