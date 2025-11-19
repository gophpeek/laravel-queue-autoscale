<?php

declare(strict_types=1);

return [
    'enabled' => env('QUEUE_AUTOSCALE_ENABLED', true),
    'manager_id' => env('QUEUE_AUTOSCALE_MANAGER_ID', gethostname()),

    'sla_defaults' => [
        'max_pickup_time_seconds' => 30,
        'min_workers' => 1,
        'max_workers' => 10,
        'scale_cooldown_seconds' => 60,
    ],

    'queues' => [],

    'prediction' => [
        'trend_window_seconds' => 300,
        'forecast_horizon_seconds' => 60,
        'breach_threshold' => 0.6, // 60% of SLA - proactive scaling kicks in earlier
    ],

    'limits' => [
        'max_cpu_percent' => 85,
        'max_memory_percent' => 85,
        'worker_memory_mb_estimate' => 128,
        'reserve_cpu_cores' => 1,
    ],

    'workers' => [
        'timeout_seconds' => 3600,
        'tries' => 3,
        'sleep_seconds' => 3,
        'shutdown_timeout_seconds' => 30,
        'health_check_interval_seconds' => 10,
    ],

    'manager' => [
        'evaluation_interval_seconds' => 5,
        'log_channel' => env('QUEUE_AUTOSCALE_LOG_CHANNEL', 'stack'),
    ],

    'strategy' => \PHPeek\LaravelQueueAutoscale\Scaling\Strategies\PredictiveStrategy::class,
    'policies' => [],
];
