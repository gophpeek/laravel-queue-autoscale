<?php

declare(strict_types=1);

use PHPeek\LaravelQueueAutoscale\Configuration\ProfilePresets;
use PHPeek\LaravelQueueAutoscale\Policies\BreachNotificationPolicy;
use PHPeek\LaravelQueueAutoscale\Policies\ConservativeScaleDownPolicy;

return [
    'enabled' => env('QUEUE_AUTOSCALE_ENABLED', true),
    'manager_id' => env('QUEUE_AUTOSCALE_MANAGER_ID', gethostname()),

    /*
    |--------------------------------------------------------------------------
    | Default SLA Configuration (Balanced Profile)
    |--------------------------------------------------------------------------
    |
    | These defaults use the "Balanced" profile - a general-purpose configuration
    | suitable for typical web applications with mixed workloads.
    |
    | Available Profiles:
    | - ProfilePresets::critical()    - Mission-critical (10s SLA, 5-50 workers)
    | - ProfilePresets::highVolume()  - High-throughput (20s SLA, 3-40 workers)
    | - ProfilePresets::balanced()    - General-purpose (30s SLA, 1-10 workers) [DEFAULT]
    | - ProfilePresets::bursty()      - Sporadic spikes (60s SLA, 0-100 workers)
    | - ProfilePresets::background()  - Low-priority (300s SLA, 0-5 workers)
    |
    */
    'sla_defaults' => ProfilePresets::balanced(),

    /*
    |--------------------------------------------------------------------------
    | Queue-Specific Overrides
    |--------------------------------------------------------------------------
    |
    | Override settings for specific queues. You can use ProfilePresets or
    | customize individual settings.
    |
    | Examples:
    |
    | 'queues' => [
    |     // Use a preset profile
    |     'payments' => ProfilePresets::critical(),
    |     'emails' => ProfilePresets::highVolume(),
    |     'cleanup' => ProfilePresets::background(),
    |
    |     // Customize a profile
    |     'custom' => array_merge(ProfilePresets::balanced(), [
    |         'max_workers' => 20,
    |     ]),
    |
    |     // Full manual configuration
    |     'manual' => [
    |         'max_pickup_time_seconds' => 45,
    |         'min_workers' => 2,
    |         'max_workers' => 15,
    |         'scale_cooldown_seconds' => 90,
    |     ],
    | ],
    |
    */
    'queues' => [],

    /*
    |--------------------------------------------------------------------------
    | Prediction Configuration
    |--------------------------------------------------------------------------
    |
    | Controls how the predictive scaling algorithm behaves.
    |
    */
    'prediction' => [
        'trend_window_seconds' => 300,          // 5 minutes historical window
        'forecast_horizon_seconds' => 60,       // 1 minute ahead prediction
        'breach_threshold' => 0.5,              // Act at 50% of SLA for proactive scaling
    ],

    /*
    |--------------------------------------------------------------------------
    | Resource Constraints
    |--------------------------------------------------------------------------
    |
    | System resource limits to prevent over-provisioning workers.
    |
    */
    'limits' => [
        'max_cpu_percent' => 85,
        'max_memory_percent' => 85,
        'worker_memory_mb_estimate' => 128,
        'reserve_cpu_cores' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for spawned queue workers.
    |
    */
    'workers' => [
        'timeout_seconds' => 3600,
        'tries' => 3,
        'sleep_seconds' => 3,
        'shutdown_timeout_seconds' => 30,
        'health_check_interval_seconds' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Manager Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for the autoscale manager process.
    |
    */
    'manager' => [
        'evaluation_interval_seconds' => 5,
        'log_channel' => env('QUEUE_AUTOSCALE_LOG_CHANNEL', 'stack'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scaling Strategy
    |--------------------------------------------------------------------------
    |
    | The strategy class responsible for calculating target worker counts.
    |
    */
    'strategy' => \PHPeek\LaravelQueueAutoscale\Scaling\Strategies\PredictiveStrategy::class,

    /*
    |--------------------------------------------------------------------------
    | Scaling Policies
    |--------------------------------------------------------------------------
    |
    | Policies modify scaling behavior. Policies are executed in order and can
    | modify scaling decisions before they are applied.
    |
    | Available Policies:
    | - ConservativeScaleDownPolicy - Limit scale-down to 1 worker per cycle
    | - AggressiveScaleDownPolicy   - Allow rapid scale-down when idle
    | - NoScaleDownPolicy           - Prevent all scale-down (critical workloads)
    | - BreachNotificationPolicy    - Log/alert on SLA breaches
    |
    | Recommended Combinations by Profile:
    | - Critical:    [NoScaleDownPolicy::class, BreachNotificationPolicy::class]
    | - High-Volume: [ConservativeScaleDownPolicy::class, BreachNotificationPolicy::class]
    | - Balanced:    [ConservativeScaleDownPolicy::class]
    | - Bursty:      [AggressiveScaleDownPolicy::class, BreachNotificationPolicy::class]
    | - Background:  [AggressiveScaleDownPolicy::class]
    |
    */
    'policies' => [
        ConservativeScaleDownPolicy::class,  // Default: Conservative scale-down
        BreachNotificationPolicy::class,     // Default: Monitor SLA compliance
    ],
];
