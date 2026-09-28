<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\ShopInstallation;
use App\Models\Subscription;
use App\Services\Shopify\BillingService;
use Illuminate\Http\Request;
use Throwable;

/**
 * Billing is Shopify Managed Pricing now - the app never creates or cancels
 * subscriptions itself (see BillingService::syncFromWebhookPayload), so this
 * controller is read-only: what plans exist, what the shop is currently on,
 * and a deep link to Shopify's own plan-picker for changing it.
 */
class BillingController extends Controller
{
    public function plans()
    {
        return response()->json([
            'plans' => Plan::where('active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function current(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        // Self-heal against a missed/delayed webhook: a shop with nothing
        // recorded at all is cheap to double-check live (this only ever
        // fires for shops that currently look unsubscribed, never on every
        // request), and it's exactly the gap that left a real reinstalled
        // shop stuck looking unsubscribed after actually completing
        // checkout on Shopify's side.
        if (! $shop->plan) {
            try {
                app(BillingService::class)->syncActivePlanViaApi($shop);
                $shop->refresh();
            } catch (Throwable) {
                // Best-effort - the merchant still sees an honest
                // "no plan" state below rather than a broken page.
            }
        }

        $hasAccess = $shop->hasPlanAccess();
        $plan = $hasAccess ? Plan::findByKey($shop->plan) : null;

        return response()->json([
            'plan' => $plan,
            // True when Shopify still shows this subscription active;
            // false while only the grace period (plan_expires_at) is
            // keeping access alive after a cancellation - Billing.jsx uses
            // this to show "cancelled, access until <date>" instead of
            // implying everything is normal.
            'subscription_active' => $shop->shopify_subscription_id !== null
                && Subscription::where('shopify_charge_id', $shop->shopify_subscription_id)->value('status') === 'active',
            'plan_expires_at' => $shop->plan_expires_at,
            'manage_url' => sprintf(
                'https://admin.shopify.com/store/%s/charges/%s/pricing_plans',
                str_replace('.myshopify.com', '', $shop->shop_domain),
                config('shopify.app_handle'),
            ),
            'usage' => [
                'script_rules' => [
                    'used' => $shop->scriptRules()->where('active', true)->count(),
                    'limit' => $plan?->script_rule_limit,
                ],
                'auto_fixes_applied' => [
                    'used' => $shop->optimizations()->where('status', 'applied')->count(),
                    'limit' => $plan?->auto_fix_limit,
                ],
            ],
        ]);
    }
}
