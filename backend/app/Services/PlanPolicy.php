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
        // A shop with no recognized/active plan (never subscribed, or past
        // its plan_expires_at grace period - see hasPlanAccess()) resolves
        // to null - every getter below defaults safely (false/0/null) in
        // that case, so an unsubscribed shop simply can't auto-fix, run
        // monitoring, etc. until it has a usable plan. There is no billable
        // "Free" tier. A cancelled-but-not-yet-expired shop still resolves
        // normally here - hasPlanAccess() is what grants the grace period.
        //
        // A test-shop-allowlisted shop passes hasPlanAccess() even with no
        // real $shop->plan key at all (e.g. Shopify never created a
        // subscription for it) - falling back to whatever plan actually
        // exists is what makes that access mean anything, since
        // Plan::findByKey(null) alone would still resolve to null and
        // every feature gate below would behave as unsubscribed anyway.
        // A genuinely unsubscribed shop (hasPlanAccess() false) resolves to
        // null here, same as always - but unlike before, null no longer
        // means "blocked from the app" (see App.jsx), it means the Free
        // tier's scan-and-display-only behavior, which every getter below
        // already defaults to.
        $this->plan = $shop->hasPlanAccess()
            ? Plan::findByKey($shop->plan) ?? Plan::fallbackForUnrecognizedKey()
            : null;
    }

    /**
     * Gates every "do something" feature at once - auto-fix, manual-fix
     * code, app/script management actions, monitoring setup - as opposed
     * to the scan-and-display core experience, which Free also gets.
     */
    public function hasPaidPlan(): bool
    {
        return $this->plan !== null && (float) $this->plan->price > 0;
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

    /**
     * Free's scan limit isn't about depth (pagesPerScan already caps that
     * at 1) - it's about how often a merchant can re-run one. Returns the
     * minimum number of days that must pass since the shop's last audit
     * before another manual scan is allowed, or null for no limit at all.
     */
    public function manualScanCooldownDays(): ?int
    {
        return $this->hasPaidPlan() ? null : 7;
    }
}
