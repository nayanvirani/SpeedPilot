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
 * The conversion-elasticity figure below is deliberately conservative, not
 * the aggressive end of what's out there. An earlier version used ~7%/second
 * (loosely, and it turned out incorrectly, inspired by Portent's ~4.4%
 * *relative conversion-rate drop* per second finding - a different, narrower
 * claim than "your total revenue moves by this % per second," which is what
 * this estimator actually applies it as) - live-tested against a real
 * merchant's own numbers, it produced a "+22.8%, roughly $1.7M-$3.1M/month"
 * estimate, which is not a credible claim for any real business regardless
 * of how large their baseline revenue is. A believable few-percent estimate
 * is more useful (and more honest) than a dramatic one nobody believes.
 * Every consumer of this output must present it as a rough estimate, never
 * a guarantee.
 */
class RevenueImpactEstimator
{
    private const CONVERSION_CHANGE_PER_SECOND = 0.01; // 1% per second of LCP

    // Keeps even a large, multi-second delta inside a range a merchant would
    // actually find credible - a "several percent" conversion claim from a
    // real speed improvement is believable; a "quarter of your revenue"
    // claim isn't, no matter how large the underlying elasticity study's
    // number technically was for a much narrower measurement window.
    private const MAX_CONVERSION_CHANGE_PCT = 0.08;

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
