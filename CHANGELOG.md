# Changelog

All notable changes to `laravel-queue-autoscale` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Initial release of Laravel Queue Autoscale
- Hybrid predictive autoscaling algorithm combining:
  - Little's Law (L = λW) for steady-state calculations
  - Trend-based predictive scaling with moving average forecasting
  - Backlog drain calculations for SLA breach prevention
- SLA/SLO-based optimization (define max pickup time instead of worker counts)
- Resource-aware scaling respecting CPU and memory limits
- Integration with `laravel-queue-metrics` for queue discovery and metrics collection
- Graceful worker lifecycle management (spawn, monitor, terminate)
- Event broadcasting (ScalingDecisionMade, WorkersScaled, SlaBreachPredicted)
- Extension points:
  - ScalingStrategyContract interface for custom strategies
  - ScalingPolicy interface for before/after hooks
- Configuration system with per-queue overrides
- Comprehensive test suite (76 tests, 146 assertions, 100% passing)
- Complete documentation:
  - README.md with quick start and usage guide
  - ARCHITECTURE.md with algorithm deep dive and queueing theory
  - TROUBLESHOOTING.md with common issues and debugging tips
  - CONTRIBUTING.md with development guidelines
  - SECURITY.md with security policy and best practices
- Production-ready examples:
  - TimeBasedStrategy for time-of-day scaling patterns
  - CostOptimizedStrategy for conservative cost-focused scaling
  - SlackNotificationPolicy for real-time Slack alerts
  - MetricsLoggingPolicy for detailed metrics logging
- Real-world configuration patterns (8 examples for different use cases)
- GitHub Actions CI/CD workflows (tests, code quality)
- Issue and PR templates for contributions

### Dependencies
- PHP 8.3+
- Laravel 11.0+
- gophpeek/laravel-queue-metrics ^1.0.0
- gophpeek/system-metrics ^1.2
- Symfony Process component

### Security
- Proper signal handling (SIGTERM, SIGINT)
- Graceful shutdown with timeout protection
- Resource limit enforcement via system metrics
- No arbitrary command execution (uses explicit command arrays)
- Worker process tracking to prevent leaks

## [0.1.0] - TBD

Initial development release.
