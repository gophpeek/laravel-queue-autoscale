<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Output\Contracts;

use PHPeek\LaravelQueueAutoscale\Output\DataTransferObjects\OutputData;

interface OutputRendererContract
{
    /**
     * Initialize the renderer (e.g., clear screen, setup TUI)
     */
    public function initialize(): void;

    /**
     * Render the current state
     */
    public function render(OutputData $data): void;

    /**
     * Handle worker stdout line
     */
    public function handleWorkerOutput(int $pid, string $line): void;

    /**
     * Cleanup on shutdown (e.g., restore terminal)
     */
    public function shutdown(): void;

    /**
     * Get queued actions for external processing (e.g., worker control)
     *
     * @return array<string, mixed>
     */
    public function getActionQueue(): array;
}
