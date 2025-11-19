<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Contracts;

use PHPeek\LaravelQueueAutoscale\Scaling\ScalingDecision;

interface ScalingPolicy
{
    /**
     * Execute before scaling action
     */
    public function beforeScaling(ScalingDecision $decision): void;

    /**
     * Execute after scaling action
     */
    public function afterScaling(ScalingDecision $decision): void;
}
