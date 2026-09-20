<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\ShopInstallation;

/**
 * Single source of truth for what a shop's plan allows, read from the
 * `plans` table (editable from /admin/plans, not a redeploy). Keeps plan
 * gating out of scattered if-checks across controllers/jobs.
 */
class PlanPolicy
{
    private ?Plan $plan;

    public function __construct(private readonly ShopInstallation $shop)
    {
        // A shop with no recognized/active plan (not yet subscribed, or
        // uninstalled) resolves to null - every getter below defaults
        // safely (false/0/null) in that case, so an unsubscribed shop
        // simply can't auto-fix, run monitoring, etc. until it picks a
        // paid plan. There is no billable "Free" tier.
        $this->plan = Plan::findByKey($shop->plan);
    }

    public function canAutoFix(): bool
    {
        return (bool) $this->plan?->auto_fixes;
    }

    public function autoFixLimit(): ?int
    {
        return $this->plan?->auto_fix_limit; // null = unlimited
    }

    public function canApplyMediumRiskFixes(): bool
    {
        return (bool) $this->plan?->medium_risk_fixes;
    }

    public function canSeeHighRiskRecommendations(): bool
    {
        return (bool) $this->plan?->high_risk_recommendations;
    }

    public function scriptRuleLimit(): ?int
    {
        return $this->plan?->script_rule_limit ?? 0; // null = unlimited, 0 = none
    }

    public function historyDays(): int
    {
        return $this->plan?->history_days ?? 0;
    }

    public function monitoringLevel(): string|false
    {
        return $this->plan?->monitoring ?? false;
    }

    public function hasAiRecommendations(): bool
    {
        return (bool) $this->plan?->ai_recommendations;
    }

    public function pagesPerScan(): int
    {
        return $this->plan?->pages_per_scan ?? 1;
    }
}
