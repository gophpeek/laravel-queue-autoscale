# API Reference

Complete API documentation for Laravel Queue Autoscale.

## Core Components

### Configuration
- [Configuration Objects](CONFIGURATION.md) - QueueConfiguration, ResourceLimits

### Scaling
- [Strategies](STRATEGIES.md) - ScalingStrategyContract, HybridPredictiveStrategy
- [Policies](POLICIES.md) - ScalingPolicyContract, built-in policies

### Events
- [Events](EVENTS.md) - ScalingDecisionMade, WorkersScaled, ScalingFailed

### Workers
- [Worker Management](WORKERS.md) - WorkerProcess, WorkerPool, WorkerSpawner

## Quick Reference

### Contracts

```php
// Strategy Contract
interface ScalingStrategyContract
{
    public function calculateTargetWorkers(object $metrics, QueueConfiguration $config): int;
    public function getLastReason(): string;
    public function getLastPrediction(): ?float;
}

// Policy Contract
interface ScalingPolicyContract
{
    public function beforeScaling(object $metrics, QueueConfiguration $config, int $currentWorkers): void;
    public function afterScaling(ScalingDecision $decision, QueueConfiguration $config, int $currentWorkers): void;
}
```

### Key Classes

- `AutoscaleManager` - Main orchestrator
- `ScalingEngine` - Decision calculation
- `QueueConfiguration` - Per-queue settings
- `ScalingDecision` - Scaling decision result
- `WorkerPool` - Worker collection management
- `WorkerProcess` - Individual worker process

## See Also

- [Guides](../guides/) - Implementation guides
- [Algorithms](../algorithms/) - Mathematical foundations
- [Examples](../../examples/) - Real-world usage
