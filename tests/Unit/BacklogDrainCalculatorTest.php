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

it('uses fallback logic when oldest job age is unavailable but backlog exists', function () {
    $calculator = new BacklogDrainCalculator;

    // Fallback scenario: oldest_job_age = 0 (unavailable) but backlog = 50
    // SLA target: 30s, avg job time: 1s
    // Conservative estimate: process within full SLA window
    // Jobs per worker: max(30s / 1s, 1.0) = 30 jobs
    // Workers needed: 50 / 30 = 1.666...
    expect($calculator->calculateRequiredWorkers(
        backlog: 50,
        oldestJobAge: 0, // Age unavailable (common with some queue drivers)
        slaTarget: 30,
        avgJobTime: 1.0,
        breachThreshold: 0.8,
    ))->toBe(50.0 / 30.0);
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
    // Oldest job: 24s (at 80% of SLA - exactly at threshold)
    // SLA progress: 24/30 = 0.8 (80%)
    // Base workers: 100 / (6s / 2s) = 33.33
    // Multiplier: 1.5x (80% = aggressive scaling)
    // Final: 33.33 * 1.5 = 50.0
    expect($calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 24,
        slaTarget: 30,
        avgJobTime: 2.0,
        breachThreshold: 0.8,
    ))->toBe(50.0);
});

it('calculates workers when above action threshold', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 30s, breach threshold: 0.8 = 24s action threshold
    // Oldest job: 28s (at 93% of SLA - well above threshold)
    // SLA progress: 28/30 = 0.933 (93.3%)
    // Base workers: 100 / (2s / 2s) = 100
    // Multiplier: 2.0x (93% = emergency scaling)
    // Final: 100 * 2.0 = 200.0
    expect($calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 28,
        slaTarget: 30,
        avgJobTime: 2.0,
        breachThreshold: 0.8,
    ))->toBe(200.0);
});

it('scales aggressively when SLA is already breached', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 30s, oldest job: 35s (breached by 5s - 116.6% of SLA)
    // SLA progress: 35/30 = 1.166 (116.6%)
    // Base workers: 100 / max(2.0, 0.1) = 50
    // Multiplier: 3.0x (>100% = maximum aggression)
    // Final: 50 * 3.0 = 150.0
    expect($calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 35,
        slaTarget: 30,
        avgJobTime: 2.0,
        breachThreshold: 0.8,
    ))->toBe(150.0);
});

it('scales aggressively when SLA is exactly at breach point', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 30s, oldest job: 30s (exactly at breach - 100% of SLA)
    // SLA progress: 30/30 = 1.0 (100%)
    // Base workers: 100 / max(2.0, 0.1) = 50
    // Multiplier: 3.0x (100% = maximum aggression)
    // Final: 50 * 3.0 = 150.0
    expect($calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 30,
        slaTarget: 30,
        avgJobTime: 2.0,
        breachThreshold: 0.8,
    ))->toBe(150.0);
});

it('handles fast jobs requiring fewer workers', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 60s, oldest job: 50s (83.3% of SLA)
    // SLA progress: 50/60 = 0.833 (83.3%)
    // Base workers: 50 / (10s / 0.5s) = 2.5
    // Multiplier: 1.5x (83% = aggressive scaling)
    // Final: 2.5 * 1.5 = 3.75
    expect($calculator->calculateRequiredWorkers(
        backlog: 50,
        oldestJobAge: 50,
        slaTarget: 60,
        avgJobTime: 0.5,
        breachThreshold: 0.8,
    ))->toBe(3.75);
});

it('handles slow jobs requiring more workers', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 30s, oldest job: 25s (83.3% of SLA)
    // SLA progress: 25/30 = 0.833 (83.3%)
    // Base workers: 20 / max(5s / 10s, 1.0) = 20
    // Multiplier: 1.5x (83% = aggressive scaling)
    // Final: 20 * 1.5 = 30.0
    expect($calculator->calculateRequiredWorkers(
        backlog: 20,
        oldestJobAge: 25,
        slaTarget: 30,
        avgJobTime: 10.0,
        breachThreshold: 0.8,
    ))->toBe(30.0);
});

it('handles different breach thresholds', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 100s, breach threshold: 0.5 = 50s action threshold
    // Oldest job: 60s (60% of SLA)
    // SLA progress: 60/100 = 0.6 (60%)
    // Base workers: 80 / (40s / 5s) = 10
    // Multiplier: 1.2x (60% = early preparation)
    // Final: 10 * 1.2 = 12.0
    expect($calculator->calculateRequiredWorkers(
        backlog: 80,
        oldestJobAge: 60,
        slaTarget: 100,
        avgJobTime: 5.0,
        breachThreshold: 0.5,
    ))->toBe(12.0);
});

it('handles small backlog efficiently', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 30s, oldest job: 25s (83.3% of SLA)
    // SLA progress: 25/30 = 0.833 (83.3%)
    // Base workers: 5 / (5s / 2s) = 2.0
    // Multiplier: 1.5x (83% = aggressive scaling)
    // Final: 2.0 * 1.5 = 3.0
    expect($calculator->calculateRequiredWorkers(
        backlog: 5,
        oldestJobAge: 25,
        slaTarget: 30,
        avgJobTime: 2.0,
        breachThreshold: 0.8,
    ))->toBe(3.0);
});

it('protects against division by very small avgJobTime in breach scenario', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 30s, oldest job: 35s (116.6% of SLA - already breached)
    // SLA progress: 35/30 = 1.166 (116.6%)
    // Base workers: 100 / max(0.01, 0.1) = 1000 (protection against tiny avgJobTime)
    // Multiplier: 3.0x (>100% = maximum aggression)
    // Final: 1000 * 3.0 = 3000.0
    expect($calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 35,
        slaTarget: 30,
        avgJobTime: 0.01,
        breachThreshold: 0.8,
    ))->toBe(3000.0);
});
