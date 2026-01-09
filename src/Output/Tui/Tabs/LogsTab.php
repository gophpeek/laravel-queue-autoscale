<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Output\Tui\Tabs;

use PHPeek\LaravelQueueAutoscale\Output\Tui\Events\KeyBindings;
use PHPeek\LaravelQueueAutoscale\Output\Tui\State\TuiState;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Widget;

/**
 * Logs tab showing searchable worker output
 */
final class LogsTab implements TabContract
{
    private const TAB_INDEX = 4;

    private const PAGE_SIZE = 25;

    private bool $autoScroll = true;

    public function getName(): string
    {
        return 'Logs';
    }

    public function getShortcut(): string
    {
        return '5';
    }

    public function render(TuiState $state, Area $area): Widget
    {
        $logs = $this->getFilteredLogs($state);
        $scrollOffset = $state->navigation->getScrollOffset(self::TAB_INDEX);
        $totalLogs = count($logs);

        // Calculate visible height (accounting for borders)
        $visibleHeight = max(1, $area->height - 3);

        // Auto-scroll to bottom when new logs arrive
        if ($this->autoScroll && $totalLogs > $visibleHeight) {
            $scrollOffset = max(0, $totalLogs - $visibleHeight);
            $state->navigation->setScrollOffset(self::TAB_INDEX, $scrollOffset);
        }

        $lines = [];

        // Get visible slice of logs
        $visibleLogs = array_slice($logs, $scrollOffset, $visibleHeight, true);

        foreach ($visibleLogs as $log) {
            $timestamp = $log['timestamp']->format('H:i:s');
            $pid = $log['pid'];
            $line = $this->sanitizeLine($log['line']);

            // Color code log levels
            $lineStyle = Style::default();
            if (str_contains($line, 'error') || str_contains($line, 'Error') || str_contains($line, 'Failed:')) {
                $lineStyle = Style::default()->red();
            } elseif (str_contains($line, 'warning') || str_contains($line, 'Warning')) {
                $lineStyle = Style::default()->yellow();
            } elseif (str_contains($line, 'Processing:')) {
                $lineStyle = Style::default()->cyan();
            } elseif (str_contains($line, 'Processed:')) {
                $lineStyle = Style::default()->green();
            }

            // Highlight filter matches
            $filter = $state->filter->getTabFilter(self::TAB_INDEX);
            if ($filter !== '' && stripos($line, $filter) !== false) {
                $lineStyle = $lineStyle->onYellow()->black();
            }

            $lines[] = Line::fromSpans(
                Span::styled("[{$timestamp}]", Style::default()->gray()),
                Span::styled(" [W{$pid}] ", Style::default()->cyan()),
                Span::styled($this->truncateLine($line, $area->width - 25), $lineStyle)
            );
        }

        if (empty($logs)) {
            $lines[] = Line::fromString('No worker output yet');
        }

        // Build title with stats
        $filter = $state->filter->getTabFilter(self::TAB_INDEX);
        $filterInfo = $filter !== '' ? " | Filter: {$filter}" : '';
        $scrollInfo = $totalLogs > $visibleHeight
            ? sprintf(' | Line %d-%d of %d', $scrollOffset + 1, min($scrollOffset + $visibleHeight, $totalLogs), $totalLogs)
            : '';
        $autoScrollInfo = $this->autoScroll ? ' | Auto' : '';

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromString(" Worker Output{$scrollInfo}{$autoScrollInfo}{$filterInfo} "))
            ->widget(ParagraphWidget::fromLines(...$lines));
    }

    public function handleCharKey(CharKeyEvent $event, TuiState $state): bool
    {
        $totalLogs = count($this->getFilteredLogs($state));

        if (KeyBindings::isNavigateUp($event)) {
            $this->autoScroll = false;
            $state->navigation->scrollUp(self::TAB_INDEX);

            return true;
        }

        if (KeyBindings::isNavigateDown($event)) {
            $scrollOffset = $state->navigation->getScrollOffset(self::TAB_INDEX);
            $state->navigation->scrollDown(self::TAB_INDEX, $totalLogs);

            // Re-enable auto-scroll if at bottom
            if ($state->navigation->getScrollOffset(self::TAB_INDEX) >= $totalLogs - self::PAGE_SIZE) {
                $this->autoScroll = true;
            }

            return true;
        }

        // 'g' goes to top
        if ($event->char === 'g') {
            $this->autoScroll = false;
            $state->navigation->setScrollOffset(self::TAB_INDEX, 0);

            return true;
        }

        // 'G' goes to bottom and enables auto-scroll
        if ($event->char === 'G') {
            $this->autoScroll = true;
            $state->navigation->setScrollOffset(self::TAB_INDEX, max(0, $totalLogs - self::PAGE_SIZE));

            return true;
        }

        // 'a' toggles auto-scroll
        if ($event->char === 'a') {
            $this->autoScroll = ! $this->autoScroll;

            return true;
        }

        return false;
    }

    public function handleCodedKey(CodedKeyEvent $event, TuiState $state): bool
    {
        $totalLogs = count($this->getFilteredLogs($state));

        if (KeyBindings::isNavigateUp($event)) {
            $this->autoScroll = false;
            $state->navigation->scrollUp(self::TAB_INDEX);

            return true;
        }

        if (KeyBindings::isNavigateDown($event)) {
            $state->navigation->scrollDown(self::TAB_INDEX, $totalLogs);
            if ($state->navigation->getScrollOffset(self::TAB_INDEX) >= $totalLogs - self::PAGE_SIZE) {
                $this->autoScroll = true;
            }

            return true;
        }

        if (KeyBindings::isPageUp($event)) {
            $this->autoScroll = false;
            $state->navigation->pageUp(self::TAB_INDEX, $totalLogs);

            return true;
        }

        if (KeyBindings::isPageDown($event)) {
            $state->navigation->pageDown(self::TAB_INDEX, $totalLogs);
            if ($state->navigation->getScrollOffset(self::TAB_INDEX) >= $totalLogs - self::PAGE_SIZE) {
                $this->autoScroll = true;
            }

            return true;
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    public function getStatusBarHints(): array
    {
        $hints = [
            'j/k' => 'scroll',
            'g/G' => 'top/bottom',
            'u/d' => 'page',
        ];

        $hints['a'] = $this->autoScroll ? 'auto:on' : 'auto:off';

        return $hints;
    }

    public function onActivate(TuiState $state): void
    {
        // Enable auto-scroll by default when entering logs tab
        $this->autoScroll = true;
    }

    public function onDeactivate(TuiState $state): void
    {
        // No cleanup needed
    }

    public function scrollUp(TuiState $state): void
    {
        $state->navigation->moveUp();
    }

    public function scrollDown(TuiState $state): void
    {
        $state->navigation->moveDown();
    }

    /**
     * @return array<int, array{timestamp: \DateTimeImmutable, pid: int, line: string}>
     */
    private function getFilteredLogs(TuiState $state): array
    {
        return $state->getFilteredWorkerLogs(self::TAB_INDEX);
    }

    private function truncateLine(string $line, int $maxLength): string
    {
        $line = trim($line);

        if (mb_strlen($line) <= $maxLength) {
            return $line;
        }

        return mb_substr($line, 0, $maxLength - 3).'...';
    }

    /**
     * Sanitize worker output line to fix Laravel's duration bug.
     *
     * When Laravel's WorkCommand has a null $latestStartedAt (race condition),
     * it calculates duration from epoch, resulting in "60yrs 10mos..." output.
     * This method detects and replaces such obviously wrong durations.
     */
    private function sanitizeLine(string $line): string
    {
        // Pattern matches durations with years or months (impossible for queue jobs)
        // e.g., "60yrs 10mos 3w 21h 50m 7s" or "55yrs 2mos"
        $pattern = '/\d+yrs?\s+\d+mos?[\s\w]*/i';

        if (preg_match($pattern, $line)) {
            // Replace the obviously wrong duration with "??" to indicate unknown
            return (string) preg_replace($pattern, '??', $line);
        }

        // Also catch durations with just months if > 1 month (unlikely for queue jobs)
        $monthsPattern = '/(\d+)mos?\s+[\dwhms\s]+/i';
        if (preg_match($monthsPattern, $line, $matches)) {
            $months = (int) $matches[1];
            if ($months > 0) {
                return (string) preg_replace($monthsPattern, '??', $line);
            }
        }

        return $line;
    }
}
