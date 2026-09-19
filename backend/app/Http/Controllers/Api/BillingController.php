<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\ShopInstallation;
use Illuminate\Http\Request;

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
        $plan = Plan::findByKey($shop->plan);

        return response()->json([
            'plan' => $plan,
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
