<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\ShopInstallation;
use Illuminate\Http\Request;

/**
 * Billing is Shopify Managed Pricing now - the app never creates or cancels
 * subscriptions itself (see BillingService::syncActivePlan), so this
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

        return response()->json([
            'plan' => Plan::findByKey($shop->plan),
            'manage_url' => sprintf(
                'https://admin.shopify.com/store/%s/charges/%s/pricing_plans',
                str_replace('.myshopify.com', '', $shop->shop_domain),
                config('shopify.api_key'),
            ),
        ]);
    }
}
