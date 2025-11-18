<?php

namespace PHPeek\LaravelQueueAutoscale\Commands;

use Illuminate\Console\Command;

class LaravelQueueAutoscaleCommand extends Command
{
    public $signature = 'laravel-queue-autoscale';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
