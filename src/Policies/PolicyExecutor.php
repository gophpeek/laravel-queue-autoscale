<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Policies;

use Illuminate\Support\Facades\Log;
use PHPeek\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use PHPeek\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use PHPeek\LaravelQueueAutoscale\Scaling\ScalingDecision;

final readonly class PolicyExecutor
{
    /** @var array<int, ScalingPolicy> */
    private array $policies;

    public function __construct()
    {
        $this->policies = $this->loadPolicies();
    }

    /**
     * Execute all policies before scaling
     *
     * Policies can modify the scaling decision by returning a new ScalingDecision.
     * Each policy receives the potentially modified decision from previous policies.
     *
     * @return ScalingDecision The final decision after all policies have been applied
     */
    public function beforeScaling(ScalingDecision $decision): ScalingDecision
    {
        $currentDecision = $decision;

        foreach ($this->policies as $policy) {
            try {
                $modifiedDecision = $policy->beforeScaling($currentDecision);

                // If policy returns a modified decision, use it for subsequent policies
                if ($modifiedDecision !== null) {
                    $currentDecision = $modifiedDecision;
                }
            } catch (\Throwable $e) {
                Log::channel(AutoscaleConfiguration::logChannel())->error(
                    'Policy beforeScaling failed',
                    [
                        'policy' => get_class($policy),
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }

        return $currentDecision;
    }

    public function afterScaling(ScalingDecision $decision): void
    {
        foreach ($this->policies as $policy) {
            try {
                $policy->afterScaling($decision);
            } catch (\Throwable $e) {
                Log::channel(AutoscaleConfiguration::logChannel())->error(
                    'Policy afterScaling failed',
                    [
                        'policy' => get_class($policy),
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }
    }

    /** @return array<int, ScalingPolicy> */
    private function loadPolicies(): array
    {
        $policyClasses = AutoscaleConfiguration::policyClasses();

        return array_map(
            fn (string $class) => app($class),
            $policyClasses
        );
    }
}
