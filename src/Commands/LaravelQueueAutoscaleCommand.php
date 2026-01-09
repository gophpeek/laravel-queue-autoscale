<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Commands;

use Illuminate\Console\Command;
use PHPeek\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use PHPeek\LaravelQueueAutoscale\Manager\AutoscaleManager;
use PHPeek\LaravelQueueAutoscale\Output\Contracts\OutputRendererContract;
use PHPeek\LaravelQueueAutoscale\Output\Renderers\DefaultOutputRenderer;
use PHPeek\LaravelQueueAutoscale\Output\Renderers\QuietOutputRenderer;
use PHPeek\LaravelQueueAutoscale\Output\Renderers\VerboseOutputRenderer;

class LaravelQueueAutoscaleCommand extends Command
{
    public $signature = 'queue:autoscale
                        {--interval=5 : Evaluation interval in seconds}
                        {--interactive : Enable interactive TUI mode with split panes}
                        {--tui : Alias for --interactive}';

    public $description = 'Intelligent queue autoscaling daemon with predictive SLA-based scaling';

    public function handle(AutoscaleManager $manager): int
    {
        if (! AutoscaleConfiguration::isEnabled()) {
            $this->error('Queue autoscale is disabled in config');

            return self::FAILURE;
        }

        $renderer = $this->createRenderer();
        $isTuiMode = $this->option('interactive') || $this->option('tui');

        if (! $isTuiMode) {
            $this->info('Starting Queue Autoscale Manager');
            $this->info('   Manager ID: '.AutoscaleConfiguration::managerId());
            $interval = $this->getInterval();
            $this->info('   Evaluation interval: '.$interval.'s');
            $this->line('');
        }

        $manager->configure($this->getInterval());
        $manager->setOutput($this->output);
        $manager->setRenderer($renderer);

        return $manager->run();
    }

    private function createRenderer(): OutputRendererContract
    {
        if ($this->output->isQuiet()) {
            return new QuietOutputRenderer;
        }

        if ($this->option('interactive') || $this->option('tui')) {
            return $this->createTuiRenderer();
        }

        if ($this->output->isVerbose()) {
            return new VerboseOutputRenderer($this->output);
        }

        return new DefaultOutputRenderer($this->output);
    }

    private function createTuiRenderer(): OutputRendererContract
    {
        if (! class_exists(\PhpTui\Tui\Display\Display::class)) {
            $this->warn('TUI mode requires php-tui/php-tui package.');
            $this->warn('Install with: composer require php-tui/php-tui');
            $this->warn('Falling back to default output mode.');
            $this->line('');

            return new DefaultOutputRenderer($this->output);
        }

        return new \PHPeek\LaravelQueueAutoscale\Output\Renderers\TuiOutputRenderer;
    }

    private function getInterval(): int
    {
        $interval = $this->option('interval');

        return is_string($interval) ? (int) $interval : 5;
    }
}
