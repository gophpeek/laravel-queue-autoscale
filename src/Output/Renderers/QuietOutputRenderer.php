<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Output\Renderers;

use PHPeek\LaravelQueueAutoscale\Output\Contracts\OutputRendererContract;
use PHPeek\LaravelQueueAutoscale\Output\DataTransferObjects\OutputData;

final class QuietOutputRenderer implements OutputRendererContract
{
    public function initialize(): void
    {
        // No-op
    }

    public function render(OutputData $data): void
    {
        // No-op
    }

    public function handleWorkerOutput(int $pid, string $line): void
    {
        // No-op
    }

    public function shutdown(): void
    {
        // No-op
    }

    /**
     * @return array<string, mixed>
     */
    public function getActionQueue(): array
    {
        return [];
    }
}
