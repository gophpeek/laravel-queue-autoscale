<?php

declare(strict_types=1);

use PHPeek\LaravelQueueAutoscale\Scaling\Calculators\BacklogDrainCalculator;

it('returns zero for empty backlog', function () {
    $calculator = new BacklogDrainCalculator;

    expect($calculator->calculateRequiredWorkers(
        backlog: 0,
        oldestJobAge: 25,
        slaTarget: 30,
        avgJobTime: 2.0,
        breachThreshold: 0.8,
    ))->toBe(0.0);
});

it('returns zero for zero average job time', function () {
    $calculator = new BacklogDrainCalculator;

    expect($calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 25,
        slaTarget: 30,
        avgJobTime: 0.0,
        breachThreshold: 0.8,
    ))->toBe(0.0);
});

it('returns zero for negative average job time', function () {
    $calculator = new BacklogDrainCalculator;

    expect($calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 25,
        slaTarget: 30,
        avgJobTime: -1.0,
        breachThreshold: 0.8,
    ))->toBe(0.0);
});

it('returns zero when oldest job is below action threshold', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 30s, breach threshold: 0.8 = 24s action threshold
    // Oldest job: 20s (below 24s threshold)
    expect($calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 20,
        slaTarget: 30,
        avgJobTime: 2.0,
        breachThreshold: 0.8,
    ))->toBe(0.0);
});

it('calculates workers when at action threshold', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 30s, breach threshold: 0.8 = 24s action threshold
    // Oldest job: 24s (at threshold)
    // Time until breach: 30 - 24 = 6s
    // Jobs per worker: 6s / 2s = 3 jobs
    // Workers needed: 100 / 3 = 33.33
    expect($calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 24,
        slaTarget: 30,
        avgJobTime: 2.0,
        breachThreshold: 0.8,
    ))->toBe(100.0 / 3.0);
});

it('calculates workers when above action threshold', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 30s, breach threshold: 0.8 = 24s action threshold
    // Oldest job: 28s (above threshold)
    // Time until breach: 30 - 28 = 2s
    // Jobs per worker: 2s / 2s = 1 job
    // Workers needed: 100 / 1 = 100
    expect($calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 28,
        slaTarget: 30,
        avgJobTime: 2.0,
        breachThreshold: 0.8,
    ))->toBe(100.0);
});

it('scales aggressively when SLA is already breached', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 30s, oldest job: 35s (breached by 5s)
    // Aggressive: ceil(100 / 2.0) = 50
    expect($calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 35,
        slaTarget: 30,
        avgJobTime: 2.0,
        breachThreshold: 0.8,
    ))->toBe(50.0);
});

it('scales aggressively when SLA is exactly at breach point', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 30s, oldest job: 30s (exactly at breach)
    // Time until breach: 0s
    // Aggressive: ceil(100 / 2.0) = 50
    expect($calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 30,
        slaTarget: 30,
        avgJobTime: 2.0,
        breachThreshold: 0.8,
    ))->toBe(50.0);
});

it('handles fast jobs requiring fewer workers', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 60s, oldest job: 50s (above 80% threshold of 48s)
    // Time until breach: 60 - 50 = 10s
    // Fast jobs: 0.5s each
    // Jobs per worker: 10s / 0.5s = 20 jobs
    // Workers needed: 50 / 20 = 2.5
    expect($calculator->calculateRequiredWorkers(
        backlog: 50,
        oldestJobAge: 50,
        slaTarget: 60,
        avgJobTime: 0.5,
        breachThreshold: 0.8,
    ))->toBe(2.5);
});

it('handles slow jobs requiring more workers', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 30s, oldest job: 25s (above 80% threshold of 24s)
    // Time until breach: 30 - 25 = 5s
    // Slow jobs: 10s each
    // Jobs per worker: max(5s / 10s, 1.0) = 1.0 (minimum)
    // Workers needed: 20 / 1.0 = 20
    expect($calculator->calculateRequiredWorkers(
        backlog: 20,
        oldestJobAge: 25,
        slaTarget: 30,
        avgJobTime: 10.0,
        breachThreshold: 0.8,
    ))->toBe(20.0);
});

it('handles different breach thresholds', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 100s, breach threshold: 0.5 = 50s action threshold
    // Oldest job: 60s (above 50s threshold)
    // Time until breach: 100 - 60 = 40s
    // Jobs per worker: 40s / 5s = 8 jobs
    // Workers needed: 80 / 8 = 10
    expect($calculator->calculateRequiredWorkers(
        backlog: 80,
        oldestJobAge: 60,
        slaTarget: 100,
        avgJobTime: 5.0,
        breachThreshold: 0.5,
    ))->toBe(10.0);
});

it('handles small backlog efficiently', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 30s, oldest job: 25s (above 80% threshold)
    // Time until breach: 5s
    // Jobs per worker: 5s / 2s = 2.5 jobs
    // Workers needed: 5 / 2.5 = 2.0
    expect($calculator->calculateRequiredWorkers(
        backlog: 5,
        oldestJobAge: 25,
        slaTarget: 30,
        avgJobTime: 2.0,
        breachThreshold: 0.8,
    ))->toBe(2.0);
});

it('protects against division by very small avgJobTime in breach scenario', function () {
    $calculator = new BacklogDrainCalculator;

    // Already breached, very small avgJobTime
    // Uses max(avgJobTime, 0.1) protection
    // ceil(100 / 0.1) = 1000
    expect($calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 35,
        slaTarget: 30,
        avgJobTime: 0.01,
        breachThreshold: 0.8,
    ))->toBe(1000.0);
});
