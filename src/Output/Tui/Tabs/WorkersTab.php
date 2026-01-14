<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Output\Tui\Tabs;

use PHPeek\LaravelQueueAutoscale\Output\DataTransferObjects\WorkerStatus;
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
 * Workers tab showing worker list with control actions
 */
final class WorkersTab implements TabContract
{
    private const TAB_INDEX = 2;

    /** @var array<string, callable> Queued actions */
    private array $pendingActions = [];

    public function getName(): string
    {
        return 'Workers';
    }

    public function getShortcut(): string
    {
        return '3';
    }

    public function render(TuiState $state, Area $area): Widget
    {
        return GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(
                Constraint::percentage(55),
                Constraint::percentage(45)
            )
            ->widgets(
                $this->buildWorkerList($state),
                $this->buildWorkerDetails($state)
            );
    }

    public function handleCharKey(CharKeyEvent $event, TuiState $state): bool
    {
        if (KeyBindings::isNavigateUp($event)) {
            $state->navigation->moveSelectionUp(self::TAB_INDEX, $this->getWorkerCount($state));

            return true;
        }

        if (KeyBindings::isNavigateDown($event)) {
            $state->navigation->moveSelectionDown(self::TAB_INDEX, $this->getWorkerCount($state));

            return true;
        }

        if (KeyBindings::isWorkerRestart($event)) {
            $this->promptRestart($state);

            return true;
        }

        if (KeyBindings::isWorkerPause($event)) {
            $this->promptPause($state);

            return true;
        }

        if (KeyBindings::isWorkerKill($event)) {
            $this->promptKill($state);

            return true;
        }

        return false;
    }

    public function handleCodedKey(CodedKeyEvent $event, TuiState $state): bool
    {
        if (KeyBindings::isNavigateUp($event)) {
            $state->navigation->moveSelectionUp(self::TAB_INDEX, $this->getWorkerCount($state));

            return true;
        }

        if (KeyBindings::isNavigateDown($event)) {
            $state->navigation->moveSelectionDown(self::TAB_INDEX, $this->getWorkerCount($state));

            return true;
        }

        if (KeyBindings::isPageUp($event)) {
            $state->navigation->pageUp(self::TAB_INDEX, $this->getWorkerCount($state));

            return true;
        }

        if (KeyBindings::isPageDown($event)) {
            $state->navigation->pageDown(self::TAB_INDEX, $this->getWorkerCount($state));

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
            'r' => 'restart',
            'p' => 'pause',
            'x' => 'kill',
        ];
    }

    public function onActivate(TuiState $state): void
    {
        $count = $this->getWorkerCount($state);
        if ($count > 0 && $state->navigation->getSelectedRow(self::TAB_INDEX) >= $count) {
            $state->navigation->setSelectedRow(self::TAB_INDEX, 0);
        }
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
     * Get pending worker actions
     *
     * @return array<string, callable>
     */
    public function getPendingActions(): array
    {
        $actions = $this->pendingActions;
        $this->pendingActions = [];

        return $actions;
    }

    private function buildWorkerList(TuiState $state): BlockWidget
    {
        $workers = $this->getFilteredWorkers($state);
        $selectedRow = $state->navigation->getSelectedRow(self::TAB_INDEX);
        $lines = [];

        // Header
        $lines[] = Line::fromSpans(
            Span::styled('  ID  PID      Status    Queue           Uptime', Style::default()->gray())
        );

        $index = 0;
        foreach ($workers as $worker) {
            $isSelected = $index === $selectedRow;
            $style = $isSelected ? Style::default()->white()->onBlue() : Style::default();

            $statusStyle = match ($worker->status) {
                'running' => Style::default()->green(),
                'idle' => Style::default()->yellow(),
                'paused' => Style::default()->cyan(),
                'dead' => Style::default()->red(),
                default => Style::default(),
            };

            $statusIcon = match ($worker->status) {
                'running' => '●',
                'idle' => '◐',
                'paused' => '‖',
                'dead' => '○',
                default => '?',
            };

            $prefix = $isSelected ? '▸' : ' ';
            $uptime = $this->formatUptime($worker->uptimeSeconds);
            $queueName = $this->truncate($worker->queue, 14);

            if ($isSelected) {
                $content = sprintf(
                    '%s %-3d %-8d %-9s %-15s %s',
                    $prefix,
                    $worker->id,
                    $worker->pid ?? 0,
                    strtoupper($worker->status),
                    $queueName,
                    $uptime
                );
                $lines[] = Line::fromSpans(Span::styled($content, $style));
            } else {
                $lines[] = Line::fromSpans(
                    Span::fromString("{$prefix} "),
                    Span::fromString(sprintf('%-3d ', $worker->id)),
                    Span::fromString(sprintf('%-8d ', $worker->pid ?? 0)),
                    Span::styled($statusIcon, $statusStyle),
                    Span::fromString(' '.sprintf('%-8s', strtoupper($worker->status))),
                    Span::fromString(sprintf('%-15s ', $queueName)),
                    Span::styled($uptime, Style::default()->gray())
                );
            }

            $index++;
        }

        if (empty($workers)) {
            $lines[] = Line::fromString('  No workers running');
        }

        $totalWorkers = count($workers);

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromString(" Workers ({$totalWorkers}) "))
            ->borderStyle(Style::default()->cyan())
            ->widget(ParagraphWidget::fromLines(...$lines));
    }

    private function buildWorkerDetails(TuiState $state): BlockWidget
    {
        $worker = $this->getSelectedWorker($state);

        if ($worker === null) {
            return BlockWidget::default()
                ->borders(Borders::ALL)
                ->borderType(BorderType::Rounded)
                ->titles(Title::fromString(' Worker Details '))
                ->widget(ParagraphWidget::fromString('  No worker selected'));
        }

        $statusStyle = match ($worker->status) {
            'running' => Style::default()->green(),
            'idle' => Style::default()->yellow(),
            'paused' => Style::default()->cyan(),
            'dead' => Style::default()->red(),
            default => Style::default(),
        };

        $statusIcon = match ($worker->status) {
            'running' => '● RUNNING',
            'idle' => '◐ IDLE',
            'paused' => '‖ PAUSED',
            'dead' => '○ DEAD',
            default => '? UNKNOWN',
        };

        $lines = [
            Line::fromSpans(
                Span::styled('  Status:     ', Style::default()->gray()),
                Span::styled($statusIcon, $statusStyle)
            ),
            Line::fromSpans(
                Span::styled('  PID:        ', Style::default()->gray()),
                Span::styled((string) ($worker->pid ?? '-'), Style::default()->white()->bold())
            ),
            Line::fromSpans(
                Span::styled('  Connection: ', Style::default()->gray()),
                Span::fromString($worker->connection)
            ),
            Line::fromSpans(
                Span::styled('  Queue:      ', Style::default()->gray()),
                Span::fromString($worker->queue)
            ),
            Line::fromSpans(
                Span::styled('  Uptime:     ', Style::default()->gray()),
                Span::fromString($this->formatUptime($worker->uptimeSeconds))
            ),
            Line::fromString(''),
        ];

        // Job stats
        $lines[] = Line::fromSpans(
            Span::styled('  Jobs:       ', Style::default()->gray()),
            Span::styled((string) $worker->jobsProcessed, Style::default()->white()->bold()),
            Span::fromString(' processed')
        );

        if ($worker->idlePercentage > 0) {
            $idleStyle = $worker->idlePercentage > 80 ? Style::default()->yellow() : Style::default()->green();
            $lines[] = Line::fromSpans(
                Span::styled('  Idle:       ', Style::default()->gray()),
                Span::styled(sprintf('%.1f%%', $worker->idlePercentage), $idleStyle)
            );
        }

        if ($worker->memoryMb !== null) {
            $memStyle = $worker->memoryMb > 128 ? Style::default()->yellow() : Style::default()->green();
            $lines[] = Line::fromSpans(
                Span::styled('  Memory:     ', Style::default()->gray()),
                Span::styled(sprintf('%.1f MB', $worker->memoryMb), $memStyle)
            );
        }

        $lines[] = Line::fromString('');

        // Current job
        if ($worker->currentJob !== null) {
            $lines[] = Line::fromSpans(
                Span::styled('  Current Job:', Style::default()->gray()->bold())
            );
            $lines[] = Line::fromSpans(
                Span::styled('    ', Style::default()),
                Span::styled($this->truncate(class_basename($worker->currentJob), 35), Style::default()->yellow())
            );
        } else {
            $lines[] = Line::fromSpans(
                Span::styled('  Current Job: ', Style::default()->gray()),
                Span::fromString('None')
            );
        }

        $lines[] = Line::fromString('');
        $lines[] = Line::fromSpans(
            Span::styled('  Actions:', Style::default()->gray()->bold())
        );
        $lines[] = Line::fromSpans(
            Span::fromString('    '),
            Span::styled('[r]', Style::default()->cyan()),
            Span::fromString('estart  '),
            Span::styled('[p]', Style::default()->cyan()),
            Span::fromString('ause  '),
            Span::styled('[x]', Style::default()->cyan()),
            Span::fromString(' kill')
        );

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromString(" Worker #{$worker->id} "))
            ->borderStyle($statusStyle)
            ->widget(ParagraphWidget::fromLines(...$lines));
    }

    private function promptRestart(TuiState $state): void
    {
        $worker = $this->getSelectedWorker($state);
        if ($worker === null) {
            return;
        }

        $state->showConfirmDialog(
            "Restart worker {$worker->id} (PID: {$worker->pid})?",
            fn () => $this->pendingActions["restart:{$worker->pid}"] = fn () => ['action' => 'restart', 'pid' => $worker->pid]
        );
    }

    private function promptPause(TuiState $state): void
    {
        $worker = $this->getSelectedWorker($state);
        if ($worker === null) {
            return;
        }

        $action = $worker->status === 'paused' ? 'resume' : 'pause';
        $state->showConfirmDialog(
            ucfirst($action)." worker {$worker->id} (PID: {$worker->pid})?",
            fn () => $this->pendingActions["{$action}:{$worker->pid}"] = fn () => ['action' => $action, 'pid' => $worker->pid]
        );
    }

    private function promptKill(TuiState $state): void
    {
        $worker = $this->getSelectedWorker($state);
        if ($worker === null) {
            return;
        }

        $state->showConfirmDialog(
            "Kill worker {$worker->id} (PID: {$worker->pid})? This will terminate the process.",
            fn () => $this->pendingActions["kill:{$worker->pid}"] = fn () => ['action' => 'kill', 'pid' => $worker->pid]
        );
    }

    private function getSelectedWorker(TuiState $state): ?WorkerStatus
    {
        $workers = array_values($this->getFilteredWorkers($state));
        $selectedRow = $state->navigation->getSelectedRow(self::TAB_INDEX);

        return $workers[$selectedRow] ?? null;
    }

    /**
     * @return array<int, WorkerStatus>
     */
    private function getFilteredWorkers(TuiState $state): array
    {
        $workers = $state->getWorkers();
        $filter = $state->filter->getTabFilter(self::TAB_INDEX);

        if ($filter === '') {
            return $workers;
        }

        return array_filter(
            $workers,
            fn (WorkerStatus $w) => $state->filter::contains($w->queue, $filter)
                || $state->filter::contains($w->status, $filter)
                || $state->filter::contains((string) $w->pid, $filter)
        );
    }

    private function getWorkerCount(TuiState $state): int
    {
        return count($this->getFilteredWorkers($state));
    }

    private function formatUptime(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = intdiv($seconds, 60);
        $secs = $seconds % 60;

        if ($minutes < 60) {
            return sprintf('%dm %02ds', $minutes, $secs);
        }

        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        return sprintf('%dh %02dm', $hours, $mins);
    }

    private function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 3).'...';
    }
}
