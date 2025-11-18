<?php

namespace PHPeek\LaravelQueueAutoscale;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use PHPeek\LaravelQueueAutoscale\Commands\LaravelQueueAutoscaleCommand;

class LaravelQueueAutoscaleServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-queue-autoscale')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_laravel_queue_autoscale_table')
            ->hasCommand(LaravelQueueAutoscaleCommand::class);
    }
}
