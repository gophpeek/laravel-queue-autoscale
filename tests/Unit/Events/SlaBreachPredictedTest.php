<?php

declare(strict_types=1);

use PHPeek\LaravelQueueAutoscale\Events\SlaBreachPredicted;
use PHPeek\LaravelQueueAutoscale\Scaling\ScalingDecision;

test('creates instance with scaling decision', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 10,
        reason: 'Predicted SLA breach',
        predictedPickupTime: 45.0,
        slaTarget: 30,
    );

    $event = new SlaBreachPredicted($decision);

    expect($event->decision)->toBe($decision)
        ->and($event->decision->connection)->toBe('redis')
        ->and($event->decision->queue)->toBe('default')
        ->and($event->decision->predictedPickupTime)->toBe(45.0);
});
