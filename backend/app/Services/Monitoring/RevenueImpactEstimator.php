<?php

namespace App\Services\Monitoring;

use App\Models\ShopInstallation;

/**
 * Turns the Dashboard's before/after LCP delta into a conversion-impact
 * estimate, and a dollar range when the merchant has entered their own
 * average order value / monthly orders (Settings > Revenue inputs).
 *
 * Deliberately uses lab LCP for both sides of the delta (never blends in
 * real-user/RUM data for just one side) - RUM p75 tends to run higher than
 * lab LCP simply because it captures real devices/networks, so mixing a lab
 * "before" with a RUM "after" could show a misleading regression even when
 * the fix genuinely helped. RUM stays a separate, already-shown figure on
 * the Monitoring page instead.
 *
 * The conversion-elasticity figure below is a disclosed, round industry
 * average from published retail page-speed studies (e.g. Portent's ~4.4%
 * per second on desktop e-commerce conversion, and Google/Deloitte's
 * "Milliseconds Make Millions" mobile retail findings) - not a measurement
 * of this specific store's actual visitors. Every consumer of this output
 * must present it as an estimate, never a guarantee.
 */
class RevenueImpactEstimator
{
    private const CONVERSION_CHANGE_PER_SECOND = 0.07; // 7% per second of LCP

    // The underlying studies measure conversion sensitivity within a normal
    // few-hundred-ms-to-low-seconds range - linearly extrapolating that
    // straight through a large delta (e.g. a first-ever scan against a
    // since-heavily-optimized store, 6+ seconds of LCP improvement) produces
    // an implausible number (confirmed live: a 6s delta computed as "42%
    // conversion lift", which would undermine trust in this figure rather
    // than build it). Capping keeps every shown number inside a range the
    // cited studies actually support.
    private const MAX_CONVERSION_CHANGE_PCT = 0.30;

    /**
     * @return array{
     *   lcp_delta_seconds: float,
     *   conversion_change_pct: float,
     *   monthly_revenue_low: ?float,
     *   monthly_revenue_high: ?float,
     * }|null
     */
    public function estimate(ShopInstallation $shop, ?float $beforeLcp, ?float $afterLcp): ?array
    {
        if ($beforeLcp === null || $afterLcp === null) {
            return null;
        }

        $lcpDeltaSeconds = $beforeLcp - $afterLcp; // positive = faster = improvement
        $conversionChangePct = max(
            -self::MAX_CONVERSION_CHANGE_PCT,
            min(self::MAX_CONVERSION_CHANGE_PCT, $lcpDeltaSeconds * self::CONVERSION_CHANGE_PER_SECOND),
        );

        $result = [
            'lcp_delta_seconds' => round($lcpDeltaSeconds, 2),
            'conversion_change_pct' => round($conversionChangePct * 100, 1),
            'monthly_revenue_low' => null,
            'monthly_revenue_high' => null,
        ];

        // A dollar figure is only ever shown when built on the merchant's
        // own real numbers - never a guessed/default AOV or traffic figure,
        // which would look precise while being fabricated.
        if ($shop->avg_order_value !== null && $shop->monthly_orders !== null) {
            $baseline = $shop->avg_order_value * $shop->monthly_orders;
            $pointEstimate = $baseline * $conversionChangePct;

            // A range, not one precise number - the elasticity itself is an
            // industry average, not measured for this store, so +/-30%
            // reflects that uncertainty honestly instead of overclaiming.
            $result['monthly_revenue_low'] = round(min($pointEstimate * 0.7, $pointEstimate * 1.3), 2);
            $result['monthly_revenue_high'] = round(max($pointEstimate * 0.7, $pointEstimate * 1.3), 2);
        }

        return $result;
    }
}
