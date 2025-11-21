<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Commands;

use Illuminate\Console\Command;
use PHPeek\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use PHPeek\LaravelQueueAutoscale\Manager\AutoscaleManager;

class LaravelQueueAutoscaleCommand extends Command
{
    public $signature = 'queue:autoscale
                        {--interval=5 : Evaluation interval in seconds}';

    public $description = 'Intelligent queue autoscaling daemon with predictive SLA-based scaling';

    public function handle(AutoscaleManager $manager): int
    {
        if (! AutoscaleConfiguration::isEnabled()) {
            $this->error('Queue autoscale is disabled in config');

            return self::FAILURE;
        }

        $this->info('🚀 Starting Queue Autoscale Manager');
        $this->info('   Manager ID: '.AutoscaleConfiguration::managerId());
        $interval = is_string($this->option('interval')) ? (int) $this->option('interval') : 5;
        $this->info('   Evaluation interval: '.$interval.'s');
        $this->line('');

        $manager->configure($interval);
        $manager->setOutput($this->output);

        return $manager->run();
    }
}
