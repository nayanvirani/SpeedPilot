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
        // A shop with no recognized/active plan (not yet subscribed, or
        // uninstalled) resolves to an empty plan - every getter below
        // already defaults safely (false/0/null) when a key is missing, so
        // an unsubscribed shop simply can't auto-fix, run monitoring, etc.
        // until it picks Starter or Pro. There is no billable "Free" tier.
        $this->plan = config("speedpilot.plans.{$shop->plan}") ?? [];
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
