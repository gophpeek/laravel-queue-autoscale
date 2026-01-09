<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Output\Tui\Events;

use PhpTui\Term\Actions;
use PhpTui\Term\Event;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\FocusEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\Event\TerminalResizedEvent;
use PhpTui\Term\EventProvider;
use PhpTui\Term\Terminal;

/**
 * Handles keyboard event polling and terminal lifecycle
 */
final class EventLoop
{
    private Terminal $terminal;

    private EventProvider $events;

    private bool $initialized = false;

    public function __construct()
    {
        $this->terminal = Terminal::new();
        $this->events = $this->terminal->events();
    }

    /**
     * Initialize raw mode for keyboard capture and mouse capture
     */
    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        // Enable raw mode for direct key capture
        $this->terminal->enableRawMode();

        // Enable alternate screen - this gives us a fresh screen buffer
        // and prevents terminal scroll history from showing
        $this->terminal->execute(Actions::alternateScreenEnable());

        // Enable mouse capture to prevent terminal scroll hijacking
        // This captures mouse events so scrolling doesn't affect terminal history
        $this->terminal->execute(Actions::enableMouseCapture());

        // Hide cursor for cleaner UI
        $this->terminal->execute(Actions::cursorHide());

        $this->initialized = true;
    }

    /**
     * Poll for the next event (non-blocking)
     */
    public function poll(): ?Event
    {
        return $this->events->next();
    }

    /**
     * Check if an event is a key event (char or coded)
     */
    public function isKeyEvent(Event $event): bool
    {
        return $event instanceof CharKeyEvent || $event instanceof CodedKeyEvent;
    }

    /**
     * Check if an event is a terminal resize
     */
    public function isResizeEvent(Event $event): bool
    {
        return $event instanceof TerminalResizedEvent;
    }

    /**
     * Check if an event is a focus event
     */
    public function isFocusEvent(Event $event): bool
    {
        return $event instanceof FocusEvent;
    }

    /**
     * Check if an event is a mouse event
     */
    public function isMouseEvent(Event $event): bool
    {
        return $event instanceof MouseEvent;
    }

    /**
     * Cleanup and restore terminal state
     */
    public function shutdown(): void
    {
        if (! $this->initialized) {
            return;
        }

        // Disable mouse capture first to stop receiving mouse escape sequences
        $this->terminal->execute(Actions::disableMouseCapture());

        // Show cursor again
        $this->terminal->execute(Actions::cursorShow());

        // Return to normal screen buffer (exit alternate screen)
        // This must be done after disabling mouse to prevent artifacts
        $this->terminal->execute(Actions::alternateScreenDisable());

        // Disable raw mode to restore normal terminal input processing
        $this->terminal->disableRawMode();

        // Flush any remaining output
        $this->terminal->flush();

        $this->initialized = false;
    }

    public function __destruct()
    {
        $this->shutdown();
    }
}
