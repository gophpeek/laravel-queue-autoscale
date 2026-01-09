<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Output\Tui\Tabs;

use PHPeek\LaravelQueueAutoscale\Output\DataTransferObjects\QueueStats;
use PHPeek\LaravelQueueAutoscale\Output\Tui\Events\KeyBindings;
use PHPeek\LaravelQueueAutoscale\Output\Tui\State\TuiState;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;

/**
 * Queues tab showing detailed queue statistics with scaling history
 */
final class QueuesTab implements TabContract
{
    private const TAB_INDEX = 1;

    public function getName(): string
    {
        return 'Queues';
    }

    public function getShortcut(): string
    {
        return '2';
    }

    public function render(TuiState $state, Area $area): Widget
    {
        return GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(
                Constraint::percentage(45),
                Constraint::percentage(55)
            )
            ->widgets(
                $this->buildQueueList($state),
                $this->buildQueueDetails($state, $area)
            );
    }

    public function handleCharKey(CharKeyEvent $event, TuiState $state): bool
    {
        if (KeyBindings::isNavigateUp($event)) {
            $state->navigation->moveSelectionUp(self::TAB_INDEX, $this->getQueueCount($state));

            return true;
        }

        if (KeyBindings::isNavigateDown($event)) {
            $state->navigation->moveSelectionDown(self::TAB_INDEX, $this->getQueueCount($state));

            return true;
        }

        return false;
    }

    public function handleCodedKey(CodedKeyEvent $event, TuiState $state): bool
    {
        if (KeyBindings::isNavigateUp($event)) {
            $state->navigation->moveSelectionUp(self::TAB_INDEX, $this->getQueueCount($state));

            return true;
        }

        if (KeyBindings::isNavigateDown($event)) {
            $state->navigation->moveSelectionDown(self::TAB_INDEX, $this->getQueueCount($state));

            return true;
        }

        if (KeyBindings::isPageUp($event)) {
            $state->navigation->pageUp(self::TAB_INDEX, $this->getQueueCount($state));

            return true;
        }

        if (KeyBindings::isPageDown($event)) {
            $state->navigation->pageDown(self::TAB_INDEX, $this->getQueueCount($state));

            return true;
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    public function getStatusBarHints(): array
    {
        return [
            'j/k' => 'select',
        ];
    }

    public function onActivate(TuiState $state): void
    {
        $count = $this->getQueueCount($state);
        if ($count > 0 && $state->navigation->getSelectedRow(self::TAB_INDEX) >= $count) {
            $state->navigation->setSelectedRow(self::TAB_INDEX, 0);
        }
    }

    public function onDeactivate(TuiState $state): void {}

    public function scrollUp(TuiState $state): void
    {
        $state->navigation->moveUp();
    }

    public function scrollDown(TuiState $state): void
    {
        $state->navigation->moveDown();
    }

    private function buildQueueList(TuiState $state): BlockWidget
    {
        $queues = $this->getFilteredQueues($state);
        $selectedRow = $state->navigation->getSelectedRow(self::TAB_INDEX);
        $lines = [];

        $index = 0;
        foreach ($queues as $stats) {
            $isSelected = $index === $selectedRow;
            $style = $isSelected ? Style::default()->white()->onBlue() : Style::default();

            $slaStyle = match ($stats->slaStatus) {
                'ok' => Style::default()->green(),
                'warning' => Style::default()->yellow(),
                'breached' => Style::default()->red(),
                default => Style::default(),
            };

            $statusIcon = match ($stats->slaStatus) {
                'ok' => '●',
                'warning' => '◐',
                'breached' => '○',
                default => '○',
            };

            $prefix = $isSelected ? '▸' : ' ';
            $queueName = str_pad(substr($stats->queue, 0, 18), 18);
            $pending = str_pad((string) $stats->depth, 6);
            $workers = "{$stats->activeWorkers}/{$stats->targetWorkers}";

            if ($isSelected) {
                $content = sprintf('%s %s  %s  %s', $prefix, $queueName, $pending, $workers);
                $lines[] = Line::fromSpans(Span::styled($content, $style));
            } else {
                $lines[] = Line::fromSpans(
                    Span::fromString("{$prefix} "),
                    Span::styled($statusIcon, $slaStyle),
                    Span::fromString(" {$queueName} {$pending}  {$workers}")
                );
            }

            $index++;
        }

        if (empty($queues)) {
            $lines[] = Line::fromString('No queues configured');
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromString(' Queues '))
            ->borderStyle(Style::default()->cyan())
            ->widget(ParagraphWidget::fromLines(...$lines));
    }

    private function buildQueueDetails(TuiState $state, Area $area): BlockWidget
    {
        $queues = $this->getFilteredQueues($state);
        $selectedRow = $state->navigation->getSelectedRow(self::TAB_INDEX);
        $queueArray = array_values($queues);

        if (empty($queueArray) || ! isset($queueArray[$selectedRow])) {
            return BlockWidget::default()
                ->borders(Borders::ALL)
                ->borderType(BorderType::Rounded)
                ->titles(Title::fromString(' Queue Details '))
                ->widget(ParagraphWidget::fromString('No queue selected'));
        }

        /** @var QueueStats $selected */
        $selected = $queueArray[$selectedRow];

        $slaStyle = match ($selected->slaStatus) {
            'ok' => Style::default()->green(),
            'warning' => Style::default()->yellow(),
            'breached' => Style::default()->red(),
            default => Style::default(),
        };

        $slaStatusText = match ($selected->slaStatus) {
            'ok' => '● OK',
            'warning' => '◐ Warning',
            'breached' => '○ BREACHED',
            default => '? Unknown',
        };

        // Queue info section
        $lines = [
            Line::fromSpans(
                Span::styled('  Connection: ', Style::default()->gray()),
                Span::fromString($selected->connection)
            ),
            Line::fromSpans(
                Span::styled('  Pending:    ', Style::default()->gray()),
                Span::styled((string) $selected->pending, Style::default()->white()->bold()),
                Span::styled('  Reserved: ', Style::default()->gray()),
                Span::styled((string) $selected->reserved, Style::default()->yellow()),
                Span::styled('  Scheduled: ', Style::default()->gray()),
                Span::styled((string) $selected->scheduled, Style::default()->cyan())
            ),
            Line::fromSpans(
                Span::styled('  Age:        ', Style::default()->gray()),
                Span::fromString("{$selected->oldestJobAge}s"),
                Span::styled('  Rate: ', Style::default()->gray()),
                Span::fromString(sprintf('%.1f/min', $selected->throughputPerMinute))
            ),
            Line::fromSpans(
                Span::styled('  Workers:    ', Style::default()->gray()),
                Span::styled("{$selected->activeWorkers}", Style::default()->white()->bold()),
                Span::fromString(" active / {$selected->targetWorkers} target")
            ),
            Line::fromSpans(
                Span::styled('  SLA:        ', Style::default()->gray()),
                Span::styled($slaStatusText, $slaStyle),
                Span::fromString(" (target: {$selected->slaTarget}s)")
            ),
            Line::fromString(''),
            Line::fromSpans(
                Span::styled('  Scaling History:', Style::default()->gray()->bold())
            ),
        ];

        // Filter scaling log for this queue
        $queueScalingLog = $this->getQueueScalingLog($state, $selected->queue);
        $visibleLogLines = min(count($queueScalingLog), max(5, $area->height - 12));

        if (empty($queueScalingLog)) {
            $lines[] = Line::fromSpans(
                Span::styled('    No scaling decisions yet', Style::default()->gray())
            );
        } else {
            $recentLogs = array_slice($queueScalingLog, -$visibleLogLines);
            foreach ($recentLogs as $entry) {
                $logStyle = Style::default();
                $icon = '  ';

                if (str_contains($entry, ' UP ')) {
                    $logStyle = Style::default()->green();
                    $icon = '▲ ';
                } elseif (str_contains($entry, ' DOWN ')) {
                    $logStyle = Style::default()->red();
                    $icon = '▼ ';
                } elseif (str_contains($entry, ' HOLD ')) {
                    $logStyle = Style::default()->yellow();
                    $icon = '◆ ';
                }

                $lines[] = Line::fromSpans(
                    Span::styled("    {$icon}", $logStyle),
                    Span::fromString($this->truncate($entry, 50))
                );
            }
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromString(" {$selected->queue} "))
            ->borderStyle($slaStyle)
            ->widget(ParagraphWidget::fromLines(...$lines));
    }

    /**
     * Get scaling log entries filtered for a specific queue
     *
     * @return array<int, string>
     */
    private function getQueueScalingLog(TuiState $state, string $queueName): array
    {
        $allLogs = $state->getScalingLog();

        return array_values(array_filter(
            $allLogs,
            fn (string $entry) => str_contains(strtolower($entry), strtolower($queueName))
        ));
    }

    /**
     * @return array<string, QueueStats>
     */
    private function getFilteredQueues(TuiState $state): array
    {
        $queues = $state->getQueueStats();
        $filter = $state->filter->getTabFilter(self::TAB_INDEX);

        if ($filter === '') {
            return $queues;
        }

        return array_filter(
            $queues,
            fn (QueueStats $q) => $state->filter::contains($q->queue, $filter)
                || $state->filter::contains($q->connection, $filter)
        );
    }

    private function getQueueCount(TuiState $state): int
    {
        return count($this->getFilteredQueues($state));
    }

    private function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 3).'...';
    }
}
