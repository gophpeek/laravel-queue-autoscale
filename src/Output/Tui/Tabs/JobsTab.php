<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Output\Tui\Tabs;

use PHPeek\LaravelQueueAutoscale\Output\DataTransferObjects\JobActivity;
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
 * Jobs tab showing job class statistics and recent activity
 */
final class JobsTab implements TabContract
{
    private const TAB_INDEX = 3;

    public function getName(): string
    {
        return 'Jobs';
    }

    public function getShortcut(): string
    {
        return '4';
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
                $this->buildJobClassStats($state, $area),
                $this->buildRecentActivity($state, $area)
            );
    }

    public function handleCharKey(CharKeyEvent $event, TuiState $state): bool
    {
        $totalClasses = count($this->getJobClassStats($state));

        if (KeyBindings::isNavigateUp($event)) {
            $state->navigation->moveSelectionUp(self::TAB_INDEX, $totalClasses);

            return true;
        }

        if (KeyBindings::isNavigateDown($event)) {
            $state->navigation->moveSelectionDown(self::TAB_INDEX, $totalClasses);

            return true;
        }

        return false;
    }

    public function handleCodedKey(CodedKeyEvent $event, TuiState $state): bool
    {
        $totalClasses = count($this->getJobClassStats($state));

        if (KeyBindings::isNavigateUp($event)) {
            $state->navigation->moveSelectionUp(self::TAB_INDEX, $totalClasses);

            return true;
        }

        if (KeyBindings::isNavigateDown($event)) {
            $state->navigation->moveSelectionDown(self::TAB_INDEX, $totalClasses);

            return true;
        }

        if (KeyBindings::isPageUp($event)) {
            $state->navigation->pageUp(self::TAB_INDEX, $totalClasses);

            return true;
        }

        if (KeyBindings::isPageDown($event)) {
            $state->navigation->pageDown(self::TAB_INDEX, $totalClasses);

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

    public function onActivate(TuiState $state): void {}

    public function onDeactivate(TuiState $state): void {}

    public function scrollUp(TuiState $state): void
    {
        $state->navigation->moveUp();
    }

    public function scrollDown(TuiState $state): void
    {
        $state->navigation->moveDown();
    }

    private function buildJobClassStats(TuiState $state, Area $area): BlockWidget
    {
        $stats = $this->getJobClassStats($state);
        $selectedRow = $state->navigation->getSelectedRow(self::TAB_INDEX);
        $lines = [];

        // Header
        $lines[] = Line::fromSpans(
            Span::styled('  Job Class                      ', Style::default()->gray()),
            Span::styled('Count ', Style::default()->gray()),
            Span::styled('Avg     ', Style::default()->gray()),
            Span::styled('Fail', Style::default()->gray())
        );

        $index = 0;
        foreach ($stats as $className => $data) {
            $isSelected = $index === $selectedRow;
            $style = $isSelected ? Style::default()->white()->onBlue() : Style::default();

            $shortName = $this->truncate(class_basename($className), 28);
            $count = $data['total'];
            $avgMs = $data['avg_duration'] > 0 ? sprintf('%dms', $data['avg_duration']) : '-';
            $failRate = $data['total'] > 0
                ? sprintf('%.0f%%', ($data['failed'] / $data['total']) * 100)
                : '-';

            $failStyle = $data['failed'] > 0 ? Style::default()->red() : Style::default()->green();

            $prefix = $isSelected ? '▸' : ' ';

            if ($isSelected) {
                $content = sprintf('%s %-28s %5d %7s %4s', $prefix, $shortName, $count, $avgMs, $failRate);
                $lines[] = Line::fromSpans(Span::styled($content, $style));
            } else {
                $lines[] = Line::fromSpans(
                    Span::fromString("{$prefix} "),
                    Span::fromString(str_pad($shortName, 28)),
                    Span::fromString(str_pad((string) $count, 6)),
                    Span::fromString(str_pad($avgMs, 8)),
                    Span::styled($failRate, $failStyle)
                );
            }

            $index++;
        }

        if (empty($stats)) {
            $lines[] = Line::fromString('  No jobs recorded yet');
        }

        $totalJobs = array_sum(array_column($stats, 'total'));

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromString(" Job Classes ({$totalJobs} total) "))
            ->borderStyle(Style::default()->cyan())
            ->widget(ParagraphWidget::fromLines(...$lines));
    }

    private function buildRecentActivity(TuiState $state, Area $area): BlockWidget
    {
        $stats = $this->getJobClassStats($state);
        $selectedRow = $state->navigation->getSelectedRow(self::TAB_INDEX);
        $statsArray = array_values($stats);

        // If a job class is selected, show its recent activity
        if (! empty($statsArray) && isset($statsArray[$selectedRow])) {
            $selectedData = $statsArray[$selectedRow];
            $className = array_keys($stats)[$selectedRow];

            return $this->buildJobClassDetail($state, $className, $selectedData);
        }

        // Default: show all recent jobs
        return $this->buildAllRecentJobs($state, $area);
    }

    /**
     * @param  array{total: int, processed: int, failed: int, processing: int, avg_duration: float, durations: array<int>}  $data
     */
    private function buildJobClassDetail(TuiState $state, string $className, array $data): BlockWidget
    {
        $lines = [];

        // Stats summary
        $lines[] = Line::fromSpans(
            Span::styled('  Class: ', Style::default()->gray()),
            Span::styled(class_basename($className), Style::default()->white()->bold())
        );
        $lines[] = Line::fromSpans(
            Span::styled('  Full:  ', Style::default()->gray()),
            Span::fromString($this->truncate($className, 40))
        );
        $lines[] = Line::fromString('');

        // Execution stats
        $lines[] = Line::fromSpans(
            Span::styled('  Processed: ', Style::default()->gray()),
            Span::styled((string) $data['processed'], Style::default()->green()),
            Span::styled('  Failed: ', Style::default()->gray()),
            Span::styled((string) $data['failed'], $data['failed'] > 0 ? Style::default()->red() : Style::default()->green()),
            Span::styled('  Active: ', Style::default()->gray()),
            Span::styled((string) $data['processing'], Style::default()->yellow())
        );

        // Duration stats
        if (! empty($data['durations'])) {
            $minDuration = min($data['durations']);
            $maxDuration = max($data['durations']);
            $avgDuration = (int) $data['avg_duration'];

            $lines[] = Line::fromSpans(
                Span::styled('  Duration: ', Style::default()->gray()),
                Span::fromString("min {$minDuration}ms / avg {$avgDuration}ms / max {$maxDuration}ms")
            );
        }

        $lines[] = Line::fromString('');
        $lines[] = Line::fromSpans(
            Span::styled('  Recent executions:', Style::default()->gray()->bold())
        );

        // Get recent jobs for this class
        $recentJobs = array_filter(
            $state->getRecentJobs(),
            fn (JobActivity $job) => $job->jobClass === $className
        );
        $recentJobs = array_slice(array_reverse($recentJobs), 0, 10);

        foreach ($recentJobs as $job) {
            $worker = $state->getWorkerByPid($job->workerId);
            $queue = $worker ? $worker->queue : '?';

            $statusIcon = match ($job->status) {
                'processing' => '○',
                'processed' => '✓',
                'failed' => '✗',
                default => '?',
            };

            $statusStyle = match ($job->status) {
                'processing' => Style::default()->yellow(),
                'processed' => Style::default()->green(),
                'failed' => Style::default()->red(),
                default => Style::default(),
            };

            $time = $job->timestamp->format('H:i:s');
            $duration = $job->durationMs !== null ? sprintf('%dms', $job->durationMs) : '...';

            $lines[] = Line::fromSpans(
                Span::fromString("    {$time} "),
                Span::styled($statusIcon, $statusStyle),
                Span::fromString(" {$queue} "),
                Span::styled($duration, Style::default()->gray())
            );
        }

        if (empty($recentJobs)) {
            $lines[] = Line::fromString('    No recent executions');
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromString(' Job Details '))
            ->borderStyle(Style::default()->white())
            ->widget(ParagraphWidget::fromLines(...$lines));
    }

    private function buildAllRecentJobs(TuiState $state, Area $area): BlockWidget
    {
        $jobs = array_slice(array_reverse($state->getRecentJobs()), 0, 20);
        $lines = [];

        foreach ($jobs as $job) {
            $worker = $state->getWorkerByPid($job->workerId);
            $queue = $worker ? $worker->queue : '?';

            $statusIcon = match ($job->status) {
                'processing' => '○',
                'processed' => '✓',
                'failed' => '✗',
                default => '?',
            };

            $statusStyle = match ($job->status) {
                'processing' => Style::default()->yellow(),
                'processed' => Style::default()->green(),
                'failed' => Style::default()->red(),
                default => Style::default(),
            };

            $time = $job->timestamp->format('H:i:s');
            $duration = $job->durationMs !== null ? sprintf('%dms', $job->durationMs) : '...';
            $shortClass = $this->truncate($job->shortClassName(), 20);

            $lines[] = Line::fromSpans(
                Span::fromString("  {$time} "),
                Span::styled($statusIcon, $statusStyle),
                Span::fromString(" {$shortClass} "),
                Span::styled($queue, Style::default()->gray()),
                Span::fromString(' '),
                Span::styled($duration, Style::default()->gray())
            );
        }

        if (empty($jobs)) {
            $lines[] = Line::fromString('  No recent jobs');
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromString(' Recent Activity '))
            ->borderStyle(Style::default()->white())
            ->widget(ParagraphWidget::fromLines(...$lines));
    }

    /**
     * Aggregate job statistics by class name
     *
     * @return array<string, array{total: int, processed: int, failed: int, processing: int, avg_duration: float, durations: array<int>}>
     */
    private function getJobClassStats(TuiState $state): array
    {
        $jobs = $state->getRecentJobs();
        $stats = [];

        foreach ($jobs as $job) {
            $class = $job->jobClass;

            if (! isset($stats[$class])) {
                $stats[$class] = [
                    'total' => 0,
                    'processed' => 0,
                    'failed' => 0,
                    'processing' => 0,
                    'avg_duration' => 0.0,
                    'durations' => [],
                ];
            }

            $stats[$class]['total']++;

            if ($job->isProcessed()) {
                $stats[$class]['processed']++;
                if ($job->durationMs !== null) {
                    $stats[$class]['durations'][] = $job->durationMs;
                }
            } elseif ($job->isFailed()) {
                $stats[$class]['failed']++;
                if ($job->durationMs !== null) {
                    $stats[$class]['durations'][] = $job->durationMs;
                }
            } elseif ($job->isProcessing()) {
                $stats[$class]['processing']++;
            }
        }

        // Calculate averages
        foreach ($stats as $class => &$data) {
            if (! empty($data['durations'])) {
                $data['avg_duration'] = array_sum($data['durations']) / count($data['durations']);
            }
        }

        // Sort by total count descending
        uasort($stats, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $stats;
    }

    private function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 3).'...';
    }
}
