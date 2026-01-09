<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Output\Tui\Events;

use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;

/**
 * Defines all keyboard shortcuts for the TUI
 */
final class KeyBindings
{
    // Tab shortcuts
    public const TAB_OVERVIEW = '1';

    public const TAB_QUEUES = '2';

    public const TAB_WORKERS = '3';

    public const TAB_JOBS = '4';

    public const TAB_LOGS = '5';

    public const TAB_METRICS = '6';

    // Navigation
    public const NAV_UP = 'k';

    public const NAV_DOWN = 'j';

    public const NAV_PAGE_UP = 'u'; // Ctrl+u style

    public const NAV_PAGE_DOWN = 'd'; // Ctrl+d style

    public const NAV_TOP = 'g';

    public const NAV_BOTTOM = 'G';

    // Actions
    public const FILTER = '/';

    public const COMMAND = ':';

    public const QUIT = 'q';

    public const HELP = '?';

    // Worker actions
    public const WORKER_RESTART = 'r';

    public const WORKER_PAUSE = 'p';

    public const WORKER_KILL = 'x';

    /**
     * Check if a char key event matches a binding
     */
    public static function matchesChar(CharKeyEvent $event, string $binding): bool
    {
        return $event->char === $binding;
    }

    /**
     * Check if the event is a quit command
     */
    public static function isQuit(CharKeyEvent $event): bool
    {
        return self::matchesChar($event, self::QUIT);
    }

    /**
     * Check if the event is a tab switch (1-6)
     */
    public static function isTabSwitch(CharKeyEvent $event): ?int
    {
        return match ($event->char) {
            self::TAB_OVERVIEW => 0,
            self::TAB_QUEUES => 1,
            self::TAB_WORKERS => 2,
            self::TAB_JOBS => 3,
            self::TAB_LOGS => 4,
            self::TAB_METRICS => 5,
            default => null,
        };
    }

    /**
     * Check if the event is navigation up (k or arrow up)
     */
    public static function isNavigateUp(CharKeyEvent|CodedKeyEvent $event): bool
    {
        if ($event instanceof CharKeyEvent) {
            return $event->char === self::NAV_UP;
        }

        return $event->code === KeyCode::Up;
    }

    /**
     * Check if the event is navigation down (j or arrow down)
     */
    public static function isNavigateDown(CharKeyEvent|CodedKeyEvent $event): bool
    {
        if ($event instanceof CharKeyEvent) {
            return $event->char === self::NAV_DOWN;
        }

        return $event->code === KeyCode::Down;
    }

    /**
     * Check if the event is page up
     */
    public static function isPageUp(CharKeyEvent|CodedKeyEvent $event): bool
    {
        if ($event instanceof CharKeyEvent) {
            return $event->char === self::NAV_PAGE_UP;
        }

        return $event->code === KeyCode::PageUp;
    }

    /**
     * Check if the event is page down
     */
    public static function isPageDown(CharKeyEvent|CodedKeyEvent $event): bool
    {
        if ($event instanceof CharKeyEvent) {
            return $event->char === self::NAV_PAGE_DOWN;
        }

        return $event->code === KeyCode::PageDown;
    }

    /**
     * Check if the event is filter mode trigger
     */
    public static function isFilter(CharKeyEvent $event): bool
    {
        return self::matchesChar($event, self::FILTER);
    }

    /**
     * Check if the event is command palette trigger
     */
    public static function isCommand(CharKeyEvent $event): bool
    {
        return self::matchesChar($event, self::COMMAND);
    }

    /**
     * Check if the event is Tab key (next tab)
     */
    public static function isNextTab(CodedKeyEvent $event): bool
    {
        return $event->code === KeyCode::Tab;
    }

    /**
     * Check if the event is BackTab (previous tab)
     */
    public static function isPreviousTab(CodedKeyEvent $event): bool
    {
        return $event->code === KeyCode::BackTab;
    }

    /**
     * Check if the event is Enter
     */
    public static function isEnter(CodedKeyEvent $event): bool
    {
        return $event->code === KeyCode::Enter;
    }

    /**
     * Check if the event is Escape
     */
    public static function isEscape(CodedKeyEvent $event): bool
    {
        return $event->code === KeyCode::Esc;
    }

    /**
     * Check if the event is Backspace
     */
    public static function isBackspace(CodedKeyEvent $event): bool
    {
        return $event->code === KeyCode::Backspace;
    }

    /**
     * Check if the event is worker restart
     */
    public static function isWorkerRestart(CharKeyEvent $event): bool
    {
        return self::matchesChar($event, self::WORKER_RESTART);
    }

    /**
     * Check if the event is worker pause
     */
    public static function isWorkerPause(CharKeyEvent $event): bool
    {
        return self::matchesChar($event, self::WORKER_PAUSE);
    }

    /**
     * Check if the event is worker kill
     */
    public static function isWorkerKill(CharKeyEvent $event): bool
    {
        return self::matchesChar($event, self::WORKER_KILL);
    }

    /**
     * Check if the event is help
     */
    public static function isHelp(CharKeyEvent $event): bool
    {
        return self::matchesChar($event, self::HELP);
    }

    /**
     * Get global key hints for status bar
     *
     * @return array<string, string>
     */
    public static function getGlobalHints(): array
    {
        return [
            'Tab' => 'next',
            '1-6' => 'tabs',
            '/' => 'filter',
            ':' => 'command',
            '?' => 'help',
            'q' => 'quit',
        ];
    }
}
