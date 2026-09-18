<?php

namespace App\Services;

use App\Models\ShopInstallation;

/**
 * Single source of truth for what a shop's plan allows, read from
 * config/speedpilot.php. Keeps plan gating out of scattered if-checks across
 * controllers/jobs.
 */
class PlanPolicy
{
    private array $plan;

    public function __construct(private readonly ShopInstallation $shop)
    {
        $this->plan = config("speedpilot.plans.{$shop->plan}") ?? config('speedpilot.plans.free');
    }

    public function canAutoFix(): bool
    {
        return (bool) ($this->plan['auto_fixes'] ?? false);
    }

    public function autoFixLimit(): ?int
    {
        return $this->plan['auto_fix_limit'] ?? null; // null = unlimited
    }

    public function canApplyMediumRiskFixes(): bool
    {
        return (bool) ($this->plan['medium_risk_fixes'] ?? false);
    }

    public function canSeeHighRiskRecommendations(): bool
    {
        return (bool) ($this->plan['high_risk_recommendations'] ?? false);
    }

    public function scriptRuleLimit(): ?int
    {
        return $this->plan['script_rule_limit'] ?? 0; // null = unlimited, 0 = none
    }

    public function historyDays(): int
    {
        return $this->plan['history_days'] ?? 0;
    }

    public function monitoringLevel(): string|false
    {
        return $this->plan['monitoring'] ?? false;
    }

    public function hasAiRecommendations(): bool
    {
        return (bool) ($this->plan['ai_recommendations'] ?? false);
    }
}
