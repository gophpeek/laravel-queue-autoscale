# Laravel Queue Autoscale - Context & Decisions

**Last Updated**: 2025-11-19

---

## 📦 Dependencies

### Installed Packages

**laravel-queue-metrics** (v0.0.1)
- Provides ALL queue and worker metrics
- API: `QueueMetrics::getAllQueuesWithMetrics()`
- Returns: `QueueMetricsData` with depth, rates, workers, health, trends

**system-metrics** (v1.2.0)
- Provides CPU/memory capacity data
- API: `SystemMetrics::limits()->getValue()`
- Returns: Available cores, memory, usage percentages

---

## 🗂️ Key Files Structure

```
src/
├── Commands/
│   └── AutoscaleCommand.php
├── Configuration/
│   ├── QueueConfiguration.php
│   └── AutoscaleConfiguration.php
├── Scaling/
│   ├── ScalingEngine.php
│   ├── ScalingDecision.php
│   ├── Calculators/
│   │   ├── LittlesLawCalculator.php
│   │   ├── TrendPredictor.php
│   │   ├── BacklogDrainCalculator.php
│   │   └── CapacityCalculator.php
│   └── Strategies/
│       ├── ScalingStrategyContract.php
│       └── PredictiveStrategy.php
├── Workers/
│   ├── WorkerProcess.php
│   ├── WorkerPool.php
│   ├── WorkerSpawner.php
│   ├── WorkerTerminator.php
│   └── ProcessHealthCheck.php
├── Manager/
│   ├── AutoscaleManager.php
│   └── SignalHandler.php
├── Policies/
│   ├── ScalingPolicy.php
│   └── PolicyExecutor.php
├── Events/
│   ├── ScalingDecisionMade.php
│   ├── WorkersScaled.php
│   └── SlaBreachPredicted.php
├── Contracts/
│   ├── ScalingStrategyContract.php
│   └── ScalingPolicy.php
└── LaravelQueueAutoscaleServiceProvider.php

config/
└── queue-autoscale.php
```

---

## 🎯 Design Decisions

### 1. No Database Layer
**Decision**: Use laravel-queue-metrics data directly, no persistence
**Rationale**: Metrics package handles all data storage and retrieval
**Impact**: Simpler architecture, zero duplication

### 2. Hybrid Predictive Algorithm
**Decision**: Combine Little's Law + Trend Analysis + Backlog Drain
**Rationale**:
- Little's Law: Steady-state workers
- Trend: Predict future demand
- Backlog: React to current queue depth
**Impact**: Proactive scaling prevents SLA breaches

### 3. SLA-First Model
**Decision**: Configure max_pickup_time_seconds instead of worker counts
**Rationale**: Business-focused (SLO) vs infrastructure-focused
**Impact**: Users think in terms of "jobs must start within 30s"

### 4. Resource Awareness
**Decision**: Factor CPU/memory limits into scaling decisions
**Rationale**: Prevent server overload regardless of queue depth
**Impact**: Safe scaling that never exceeds capacity

### 5. Extension Points
**Decision**: Provide ScalingStrategyContract and ScalingPolicy interfaces
**Rationale**: Allow custom algorithms and hooks without forking
**Impact**: High extensibility, Spatie-style DX

### 6. Single Manager Daemon
**Decision**: One `php artisan queue:autoscale` manages all queues
**Rationale**: Simpler deployment, less resource overhead
**Impact**: Single process coordinates all scaling decisions

### 7. Process-Based Workers
**Decision**: Spawn `queue:work` processes directly (not containers/cloud APIs)
**Rationale**: MVP focuses on single-server vertical scaling
**Impact**: Simple, works with supervisor/systemd, extensible later

### 8. Graceful Shutdown
**Decision**: SIGTERM with 30s timeout, then SIGKILL
**Rationale**: Allow in-flight jobs to complete safely
**Impact**: Zero job loss during scale-down

---

## 📊 Data Flow

```
1. AutoscaleManager (every 5s)
   ↓
2. QueueMetrics::getAllQueuesWithMetrics()
   → Returns all queues with backlog, rates, workers
   ↓
3. For each queue:
   a. Get QueueConfiguration (SLA settings)
   b. ScalingEngine.evaluate()
      → LittlesLawCalculator
      → TrendPredictor
      → BacklogDrainCalculator
      → CapacityCalculator (system-metrics)
      → Returns ScalingDecision
   ↓
4. Execute scaling:
   - Scale up: WorkerSpawner.spawn()
   - Scale down: WorkerTerminator.terminate()
   ↓
5. Broadcast events:
   - ScalingDecisionMade
   - WorkersScaled
```

---

## 🔧 Configuration Model

**Global defaults** in `config/queue-autoscale.php`:
```php
'sla_defaults' => [
    'max_pickup_time_seconds' => 30,
    'min_workers' => 1,
    'max_workers' => 10,
]
```

**Per-queue overrides**:
```php
'queues' => [
    'emails' => [
        'max_pickup_time_seconds' => 30,
        'min_workers' => 2,
        'max_workers' => 20,
    ],
]
```

**QueueConfiguration class** merges defaults + overrides.

---

## 🧮 Algorithm Deep Dive

### Little's Law: L = λW
- **L**: Queue length (backlog)
- **λ**: Arrival rate (jobs/sec)
- **W**: Average processing time (sec/job)
- **Workers needed**: λ × W

### Trend Prediction
- Moving average over last 5 minutes
- Forecast 60 seconds ahead
- Predict future λ

### Backlog Drain
- Calculate time until SLA breach
- Act at 80% threshold (configurable)
- Workers = backlog / (time_remaining / avg_job_time)

### Capacity Check
```php
$availableCores = SystemMetrics::limits()->availableCpuCores();
$cpuUsage = SystemMetrics::cpuUsage(1.0)->usagePercentage();
$freePercent = max(85 - $cpuUsage, 0);
$maxWorkersByCpu = floor($availableCores * $freePercent / 100);
```

---

## 🧪 Testing Strategy

**Unit Tests**:
- All calculators (Little's Law, Trend, Backlog, Capacity)
- PredictiveStrategy
- ScalingEngine decision logic

**Integration Tests**:
- AutoscaleManager full loop
- Worker spawning/termination
- Signal handling

**Mocking**:
- Mock QueueMetrics facade
- Mock SystemMetrics facade
- Mock Process for worker tests

**Coverage Target**: 90%+

---

## 🚀 Future Extensions

**Not in MVP, but designed for**:
- Horizontal scaling (cloud APIs)
- Kubernetes integration
- Custom strategies (reactive, scheduled)
- Prometheus metrics export
- Multi-manager coordination
