<?php

declare(strict_types=1);

use PHPeek\LaravelQueueAutoscale\Policies\ConservativeScaleDownPolicy;
use PHPeek\LaravelQueueAutoscale\Scaling\ScalingDecision;

beforeEach(function () {
    $this->policy = new ConservativeScaleDownPolicy;
});

test('returns null for scale up decisions', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 10,
        reason: 'Scale up needed',
    );

    $result = $this->policy->beforeScaling($decision);

    expect($result)->toBeNull();
});

test('returns null for hold decisions', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 5,
        reason: 'Hold workers',
    );

    $result = $this->policy->beforeScaling($decision);

    expect($result)->toBeNull();
});

test('returns null when scaling down by one worker', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 4,
        reason: 'Scale down by one',
    );

    $result = $this->policy->beforeScaling($decision);

    expect($result)->toBeNull();
});

test('limits scale down to one worker when removing multiple', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 10,
        targetWorkers: 5,
        reason: 'Original reason',
    );

    $result = $this->policy->beforeScaling($decision);

    expect($result)->toBeInstanceOf(ScalingDecision::class)
        ->and($result->targetWorkers)->toBe(9)
        ->and($result->currentWorkers)->toBe(10)
        ->and($result->connection)->toBe('redis')
        ->and($result->queue)->toBe('default');
});

test('preserves prediction and sla values in modified decision', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 10,
        targetWorkers: 2,
        reason: 'Original reason',
        predictedPickupTime: 15.5,
        slaTarget: 30,
    );

    $result = $this->policy->beforeScaling($decision);

    expect($result->predictedPickupTime)->toBe(15.5)
        ->and($result->slaTarget)->toBe(30);
});

test('includes original reason in modified reason', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 10,
        targetWorkers: 5,
        reason: 'Original reason',
    );

    $result = $this->policy->beforeScaling($decision);

    expect($result->reason)->toContain('ConservativeScaleDownPolicy')
        ->and($result->reason)->toContain('Original reason');
});

test('afterScaling does nothing', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 10,
        targetWorkers: 5,
        reason: 'Scale down',
    );

    $this->policy->afterScaling($decision);

    expect(true)->toBeTrue();
});
