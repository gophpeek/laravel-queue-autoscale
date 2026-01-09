<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Output\Tui\Tabs;

use PHPeek\LaravelQueueAutoscale\Output\Tui\State\TuiState;
use PHPeek\SystemMetrics\SystemMetrics;
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
 * Overview tab - Clean queue performance dashboard
 */
final class OverviewTab implements TabContract
{
    public function getName(): string
    {
        return 'Overview';
    }

    public function getShortcut(): string
    {
        return '1';
    }

    public function render(TuiState $state, Area $area): Widget
    {
        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::length(6),  // Summary header (2 lines + borders)
                Constraint::min(0),     // Queue table
                Constraint::length(6)   // Scaling activity
            )
            ->widgets(
                $this->buildSummaryHeader($state),
                $this->buildQueueTable($state),
                $this->buildScalingActivity($state)
            );
    }

    public function handleCharKey(CharKeyEvent $event, TuiState $state): bool
    {
        return false;
    }

    public function handleCodedKey(CodedKeyEvent $event, TuiState $state): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    public function getStatusBarHints(): array
    {
        return [];
    }

    public function onActivate(TuiState $state): void {}

    public function onDeactivate(TuiState $state): void {}

    public function scrollUp(TuiState $state): void {}

    public function scrollDown(TuiState $state): void {}

    private function buildSummaryHeader(TuiState $state): BlockWidget
    {
        $queues = $state->getQueueStats();

        $totalPending = array_sum(array_map(fn ($q) => $q->depth, $queues));
        $totalThroughput = array_sum(array_map(fn ($q) => $q->throughputPerMinute, $queues));
        $activeWorkers = $state->runningWorkers();
        $totalWorkers = $state->totalWorkers();

        // Calculate overall health
        $breached = count(array_filter($queues, fn ($q) => $q->slaStatus === 'breached'));
        $warning = count(array_filter($queues, fn ($q) => $q->slaStatus === 'warning'));

        $healthIcon = $breached > 0 ? '●' : ($warning > 0 ? '◐' : '●');
        $healthStyle = $breached > 0
            ? Style::default()->red()
            : ($warning > 0 ? Style::default()->yellow() : Style::default()->green());
        $healthText = $breached > 0
            ? "{$breached} SLA breached"
            : ($warning > 0 ? "{$warning} SLA warning" : 'All SLAs OK');

        // Get system resource metrics
        [$cpuPercent, $memPercent, $memUsedGb, $memTotalGb] = $this->getSystemMetrics();

        $cpuStyle = $cpuPercent > 80 ? Style::default()->red()
            : ($cpuPercent > 60 ? Style::default()->yellow() : Style::default()->green());
        $memStyle = $memPercent > 80 ? Style::default()->red()
            : ($memPercent > 60 ? Style::default()->yellow() : Style::default()->green());

        $lines = [
            Line::fromSpans(
                Span::styled("  {$healthIcon} ", $healthStyle),
                Span::styled($healthText, $healthStyle),
                Span::fromString('    '),
                Span::styled(sprintf('%d', $totalPending), Style::default()->white()->bold()),
                Span::fromString(' pending    '),
                Span::styled(sprintf('%.1f', $totalThroughput), Style::default()->white()->bold()),
                Span::fromString(' jobs/min    '),
                Span::styled("{$activeWorkers}/{$totalWorkers}", Style::default()->white()->bold()),
                Span::fromString(' workers')
            ),
            Line::fromSpans(
                Span::styled('  CPU: ', Style::default()->gray()),
                Span::styled(sprintf('%.0f%%', $cpuPercent), $cpuStyle),
                Span::styled('    RAM: ', Style::default()->gray()),
                Span::styled(sprintf('%.1f/%.1fGB (%.0f%%)', $memUsedGb, $memTotalGb, $memPercent), $memStyle)
            ),
        ];

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromString(' System Status '))
            ->borderStyle(Style::default()->cyan())
            ->widget(ParagraphWidget::fromLines(...$lines));
    }

    /**
     * Get current system CPU and memory metrics
     *
     * @return array{0: float, 1: float, 2: float, 3: float} [cpuPercent, memPercent, memUsedGb, memTotalGb]
     */
    private function getSystemMetrics(): array
    {
        $cpuPercent = 0.0;
        $memPercent = 0.0;
        $memUsedGb = 0.0;
        $memTotalGb = 0.0;

        // Get CPU usage (use short interval to not block UI)
        $cpuResult = SystemMetrics::cpuUsage(0.1);
        if ($cpuResult->isSuccess()) {
            $cpuPercent = $cpuResult->getValue()->usagePercentage();
        }

        // Get memory usage
        $memResult = SystemMetrics::memory();
        if ($memResult->isSuccess()) {
            $memory = $memResult->getValue();
            $memPercent = $memory->usedPercentage();
            $memTotalGb = $memory->totalBytes / (1024 * 1024 * 1024);
            $memUsedGb = $memory->usedBytes / (1024 * 1024 * 1024);
        }

        return [$cpuPercent, $memPercent, $memUsedGb, $memTotalGb];
    }

    private function buildQueueTable(TuiState $state): BlockWidget
    {
        $queues = $state->getQueueStats();
        $lines = [];

        // Header
        $lines[] = Line::fromSpans(
            Span::styled('  Queue              ', Style::default()->gray()),
            Span::styled('Pending  ', Style::default()->gray()),
            Span::styled('Rate      ', Style::default()->gray()),
            Span::styled('Workers   ', Style::default()->gray()),
            Span::styled('Age      ', Style::default()->gray()),
            Span::styled('SLA', Style::default()->gray())
        );

        foreach ($queues as $stats) {
            $statusStyle = match ($stats->slaStatus) {
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

            $queueName = str_pad(substr($stats->queue, 0, 16), 16);
            $pending = str_pad((string) $stats->depth, 7);
            $rate = str_pad(sprintf('%.1f/m', $stats->throughputPerMinute), 9);
            $workers = str_pad("{$stats->activeWorkers}/{$stats->targetWorkers}", 9);
            $age = str_pad($this->formatAge($stats->oldestJobAge), 8);

            $lines[] = Line::fromSpans(
                Span::styled("  {$statusIcon} ", $statusStyle),
                Span::styled($queueName, Style::default()->white()->bold()),
                Span::fromString(' '),
                Span::fromString($pending),
                Span::fromString(' '),
                Span::fromString($rate),
                Span::fromString(' '),
                Span::fromString($workers),
                Span::fromString(' '),
                Span::fromString($age),
                Span::styled(str_pad("{$stats->slaTarget}s", 5), $statusStyle)
            );
        }

        if (count($queues) === 0) {
            $lines[] = Line::fromString('  No queues configured');
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromString(' Queue Performance '))
            ->borderStyle(Style::default()->white())
            ->widget(ParagraphWidget::fromLines(...$lines));
    }

    private function buildScalingActivity(TuiState $state): BlockWidget
    {
        $log = array_slice($state->getScalingLog(), -3);
        $lines = [];

        foreach ($log as $entry) {
            if (str_contains($entry, ' UP ')) {
                $lines[] = Line::fromSpans(
                    Span::styled('  ▲ ', Style::default()->green()),
                    Span::fromString($entry)
                );
            } elseif (str_contains($entry, ' DOWN ')) {
                $lines[] = Line::fromSpans(
                    Span::styled('  ▼ ', Style::default()->red()),
                    Span::fromString($entry)
                );
            } elseif (str_contains($entry, ' HOLD ')) {
                $lines[] = Line::fromSpans(
                    Span::styled('  ◆ ', Style::default()->yellow()),
                    Span::fromString($entry)
                );
            } else {
                $lines[] = Line::fromSpans(
                    Span::fromString('    '),
                    Span::fromString($entry)
                );
            }
        }

        if (empty($lines)) {
            $lines[] = Line::fromString('  Waiting for scaling decisions...');
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromString(' Recent Scaling '))
            ->borderStyle(Style::default()->gray())
            ->widget(ParagraphWidget::fromLines(...$lines));
    }

    private function formatAge(int $seconds): string
    {
        if ($seconds === 0) {
            return '-';
        }
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return "{$minutes}m";
        }

        $hours = intdiv($minutes, 60);

        return "{$hours}h";
    }
}
