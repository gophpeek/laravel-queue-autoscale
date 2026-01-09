<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Output\Tui\Tabs;

use PHPeek\LaravelQueueAutoscale\Output\Tui\State\TuiState;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Widget\Widget;

interface TabContract
{
    /**
     * Get the display name for this tab
     */
    public function getName(): string;

    /**
     * Get the keyboard shortcut (1-6)
     */
    public function getShortcut(): string;

    /**
     * Render the tab content
     */
    public function render(TuiState $state, Area $area): Widget;

    /**
     * Handle a character key event
     *
     * @return bool True if event was handled
     */
    public function handleCharKey(CharKeyEvent $event, TuiState $state): bool;

    /**
     * Handle a coded key event (arrows, enter, etc)
     *
     * @return bool True if event was handled
     */
    public function handleCodedKey(CodedKeyEvent $event, TuiState $state): bool;

    /**
     * Get hints to display in the status bar
     *
     * @return array<string, string> Key => description
     */
    public function getStatusBarHints(): array;

    /**
     * Called when this tab becomes active
     */
    public function onActivate(TuiState $state): void;

    /**
     * Called when leaving this tab
     */
    public function onDeactivate(TuiState $state): void;

    /**
     * Handle mouse scroll up event
     */
    public function scrollUp(TuiState $state): void;

    /**
     * Handle mouse scroll down event
     */
    public function scrollDown(TuiState $state): void;
}
