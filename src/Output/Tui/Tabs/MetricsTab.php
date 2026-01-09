<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Output\Tui\Tabs;

use PHPeek\LaravelQueueAutoscale\Output\Tui\State\TuiState;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\SparklineWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;

/**
 * Metrics tab - Compact sparkline graphs and statistics
 */
final class MetricsTab implements TabContract
{
    public function getName(): string
    {
        return 'Metrics';
    }

    public function getShortcut(): string
    {
        return '6';
    }

    public function render(TuiState $state, Area $area): Widget
    {
        $queues = $state->getQueueStats();
        $queueCount = count($queues);

        // Calculate constraints dynamically based on queue count
        $constraints = [Constraint::length(7)]; // System metrics row
        for ($i = 0; $i < $queueCount; $i++) {
            $constraints[] = Constraint::length(5); // Each queue gets 5 lines
        }
        $constraints[] = Constraint::min(0); // Fill remaining space

        $widgets = [$this->buildSystemMetricsRow($state)];
        foreach ($queues as $stats) {
            $widgets[] = $this->buildQueueMetricRow($state, $stats);
        }
        $widgets[] = ParagraphWidget::fromString(''); // Spacer

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...$constraints)
            ->widgets(...$widgets);
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

    public function onActivate(TuiState $state): void
    {
        $this->recordCurrentMetrics($state);
    }

    public function onDeactivate(TuiState $state): void {}

    public function scrollUp(TuiState $state): void {}

    public function scrollDown(TuiState $state): void {}

    private function buildSystemMetricsRow(TuiState $state): GridWidget
    {
        $workerData = $this->getSparklineData($state->getMetricHistory('workers'));
        $depthData = $this->getSparklineData($state->getMetricHistory('queue_depth'));
        $throughputData = $this->getSparklineData($state->getMetricHistory('total_throughput'));

        $runningWorkers = $state->runningWorkers();
        $totalWorkers = $state->totalWorkers();
        $totalDepth = array_sum(array_map(fn ($q) => $q->depth, $state->getQueueStats()));
        $totalThroughput = array_sum(array_map(fn ($q) => $q->throughputPerMinute, $state->getQueueStats()));

        return GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(
                Constraint::percentage(33),
                Constraint::percentage(34),
                Constraint::percentage(33)
            )
            ->widgets(
                $this->buildCompactSparkline(
                    'Workers',
                    "{$runningWorkers}/{$totalWorkers}",
                    $workerData,
                    Style::default()->cyan()
                ),
                $this->buildCompactSparkline(
                    'Queue Depth',
                    (string) $totalDepth,
                    $depthData,
                    Style::default()->yellow()
                ),
                $this->buildCompactSparkline(
                    'Throughput',
                    sprintf('%.1f/m', $totalThroughput),
                    $throughputData,
                    Style::default()->green()
                )
            );
    }

    /**
     * @param  object{queue: string, slaStatus: string, throughputPerMinute: float, depth: int, activeWorkers: int, targetWorkers: int, oldestJobAge: int, slaTarget: int}  $stats
     */
    private function buildQueueMetricRow(TuiState $state, object $stats): GridWidget
    {
        $metricKey = "throughput:{$stats->queue}";
        $throughputData = $this->getSparklineData($state->getMetricHistory($metricKey));

        $depthKey = "depth:{$stats->queue}";
        $depthData = $this->getSparklineData($state->getMetricHistory($depthKey));

        $statusStyle = match ($stats->slaStatus) {
            'ok' => Style::default()->green(),
            'warning' => Style::default()->yellow(),
            'breached' => Style::default()->red(),
            default => Style::default(),
        };

        return GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(
                Constraint::percentage(50),
                Constraint::percentage(50)
            )
            ->widgets(
                $this->buildCompactSparkline(
                    "{$stats->queue} rate",
                    sprintf('%.1f/m', $stats->throughputPerMinute),
                    $throughputData,
                    $statusStyle
                ),
                $this->buildCompactSparkline(
                    "{$stats->queue} depth",
                    (string) $stats->depth,
                    $depthData,
                    $statusStyle
                )
            );
    }

    /**
     * @param  array<int<0, max>>  $data
     */
    private function buildCompactSparkline(string $label, string $value, array $data, Style $style): BlockWidget
    {
        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromString(" {$label}: {$value} "))
            ->borderStyle($style)
            ->widget(SparklineWidget::fromData(...$data)->style($style));
    }

    /**
     * @param  array<int, array{timestamp: int, value: float}>  $history
     * @return array<int<0, max>>
     */
    private function getSparklineData(array $history): array
    {
        if (empty($history)) {
            return [0];
        }

        return array_map(
            fn ($h) => max(0, (int) $h['value']),
            array_slice($history, -60)
        );
    }

    private function recordCurrentMetrics(TuiState $state): void
    {
        $state->recordMetric('workers', (float) $state->totalWorkers());

        $queues = $state->getQueueStats();
        $totalDepth = array_sum(array_map(fn ($q) => $q->depth, $queues));
        $state->recordMetric('queue_depth', (float) $totalDepth);

        $totalThroughput = array_sum(array_map(fn ($q) => $q->throughputPerMinute, $queues));
        $state->recordMetric('total_throughput', $totalThroughput);

        foreach ($queues as $stats) {
            $state->recordMetric("throughput:{$stats->queue}", $stats->throughputPerMinute);
            $state->recordMetric("depth:{$stats->queue}", (float) $stats->depth);
        }
    }
}
