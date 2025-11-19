# Laravel Queue Autoscale - Implementation Plan

**Created**: 2025-11-19
**Status**: Approved ✅

---

## 🎯 Vision

Enterprise-grade queue autoscaling med **predictive SLA/SLO-based scaling**, der proaktivt forhindrer SLA breaches gennem hybrid rate-based + trend analysis algoritme.

**Core Principles**:
- DX-first design med Spatie-patterns
- 100% stabil og predictable
- Hurtig scaling uden at overskride server thresholds
- Gentænk queues som SLA/SLO (max pickup time)
- Ingen infrastructure awareness - kun SLA targets

---

## 📦 Core Responsibilities

**WHAT WE BUILD**: Scaling Engine + Manager daemon ONLY

**WHAT WE USE**:
- `laravel-queue-metrics` - ALL queue/worker metrics
- `system-metrics` - ALL CPU/memory capacity data

**NO DATABASE** - Zero persistence layer, kun metrics consumption
**NO METRICS COLLECTION** - Delegeret til laravel-queue-metrics
**NO REST API** - Kun scaling logic

---

## 🏗️ Architecture

### Scaling Algorithm: Hybrid Predictive Strategy

**3 Components**:
1. **Rate-based (Little's Law)**: L = λW
   - Calculate steady-state workers needed

2. **Trend-based (Forecasting)**:
   - Predict future arrival rate via moving average
   - Proactive scaling før SLA breach

3. **Backlog-based (Drain Calculation)**:
   - Calculate workers needed to clear backlog before SLA breach
   - Act at 80% threshold (configurable)

**Final Decision**: `max(steady_state, predictive, backlog_drain)` within resource limits

### Resource Awareness

Integrate `system-metrics` for capacity planning:
- Max CPU % (default 85%)
- Max memory % (default 85%)
- Estimated worker footprint
- Calculate max possible workers before system overload

### Worker Lifecycle

**Spawning**:
```bash
php artisan queue:work {connection} --queue={queue} --tries=3 --max-time=3600
```

**Termination**:
1. SIGTERM (graceful)
2. Wait up to 30s
3. SIGKILL (forced)

---

## 🔧 Implementation Phases

### Phase 1: Configuration
- SLA defaults (max_pickup_time_seconds, min/max workers)
- Per-queue overrides
- Prediction settings (trend window, forecast horizon)
- Resource limits
- Worker settings

### Phase 2: Calculators
- LittlesLawCalculator
- TrendPredictor
- BacklogDrainCalculator
- CapacityCalculator

### Phase 3: Scaling Engine
- ScalingDecision DTO
- ScalingStrategyContract interface
- PredictiveStrategy implementation
- ScalingEngine orchestrator

### Phase 4: Worker Management
- WorkerProcess, WorkerPool
- WorkerSpawner
- WorkerTerminator
- ProcessHealthCheck

### Phase 5: Extension Points
- ScalingPolicy interface (before/after hooks)
- PolicyExecutor

### Phase 6: Manager Daemon
- SignalHandler
- AutoscaleManager (main loop)
- AutoscaleCommand

### Phase 7: Events
- ScalingDecisionMade
- WorkersScaled
- SlaBreachPredicted

### Phase 8: Integration
- ServiceProvider bindings
- Command registration

### Phase 9: Testing
- Unit tests for all calculators
- Integration tests for manager
- 90%+ coverage target

### Phase 10: Documentation
- README with quick start
- ARCHITECTURE.md with algorithm details

---

## ✅ Success Criteria

- ✅ Uses laravel-queue-metrics for ALL queue/worker data
- ✅ Uses system-metrics for ALL capacity data
- ✅ NO database (zero persistence)
- ✅ Predictive SLA-based scaling (proactive not reactive)
- ✅ Hybrid algorithm: Little's Law + trend + backlog
- ✅ Resource-aware (never exceeds CPU/memory)
- ✅ Auto-discovery via `QueueMetrics::getAllQueuesWithMetrics()`
- ✅ Graceful shutdown (SIGTERM → SIGKILL)
- ✅ Extension points (custom strategies, policies)
- ✅ Event broadcasting
- ✅ Zero duplication of metrics functionality

---

## 📝 Implementation Order

1. Configuration + QueueConfiguration
2. Calculators (Little's Law, Trend, Backlog, Capacity)
3. PredictiveStrategy + ScalingEngine
4. Worker management (Spawner, Terminator, Pool)
5. Manager daemon + Command
6. Extension points + Policies
7. Events
8. ServiceProvider integration
9. Testing
10. Documentation

**Testing strategy**: Pest tests written alongside each phase
