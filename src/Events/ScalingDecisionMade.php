<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use PHPeek\LaravelQueueAutoscale\Scaling\ScalingDecision;

final class ScalingDecisionMade
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly ScalingDecision $decision,
    ) {}
}
