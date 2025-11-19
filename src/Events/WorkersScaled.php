<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class WorkersScaled
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly int $from,
        public readonly int $to,
        public readonly string $action,
        public readonly string $reason,
    ) {}
}
