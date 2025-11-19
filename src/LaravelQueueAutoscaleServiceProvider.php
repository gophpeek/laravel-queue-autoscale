<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale;

use Illuminate\Support\ServiceProvider;
use PHPeek\LaravelQueueAutoscale\Commands\LaravelQueueAutoscaleCommand;
use PHPeek\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use PHPeek\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;
use PHPeek\LaravelQueueAutoscale\Manager\AutoscaleManager;
use PHPeek\LaravelQueueAutoscale\Manager\SignalHandler;
use PHPeek\LaravelQueueAutoscale\Policies\PolicyExecutor;
use PHPeek\LaravelQueueAutoscale\Scaling\Calculators\BacklogDrainCalculator;
use PHPeek\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use PHPeek\LaravelQueueAutoscale\Scaling\Calculators\LittlesLawCalculator;
use PHPeek\LaravelQueueAutoscale\Scaling\Calculators\TrendPredictor;
use PHPeek\LaravelQueueAutoscale\Scaling\ScalingEngine;
use PHPeek\LaravelQueueAutoscale\Workers\WorkerSpawner;
use PHPeek\LaravelQueueAutoscale\Workers\WorkerTerminator;

class LaravelQueueAutoscaleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/queue-autoscale.php',
            'queue-autoscale'
        );

        // Register calculators
        $this->app->singleton(LittlesLawCalculator::class);
        $this->app->singleton(TrendPredictor::class);
        $this->app->singleton(BacklogDrainCalculator::class);
        $this->app->singleton(CapacityCalculator::class);

        // Register scaling strategy from config
        $this->app->singleton(ScalingStrategyContract::class, function ($app) {
            $strategyClass = AutoscaleConfiguration::strategyClass();

            return $app->make($strategyClass);
        });

        // Register scaling engine
        $this->app->singleton(ScalingEngine::class);

        // Register worker management
        $this->app->singleton(WorkerSpawner::class);
        $this->app->singleton(WorkerTerminator::class);

        // Register policies
        $this->app->singleton(PolicyExecutor::class);

        // Register manager
        $this->app->singleton(SignalHandler::class);
        $this->app->singleton(AutoscaleManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/queue-autoscale.php' => config_path('queue-autoscale.php'),
            ], 'queue-autoscale-config');

            $this->commands([
                LaravelQueueAutoscaleCommand::class,
            ]);
        }
    }
}
