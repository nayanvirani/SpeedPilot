<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\ShopInstallation;
use App\Services\Shopify\BillingService;
use App\Services\Shopify\ShopifyGraphQLClient;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    public function plans()
    {
        return response()->json([
            'plans' => Plan::where('active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function subscribe(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'plan' => ['required', Rule::in(Plan::where('active', true)->pluck('key'))],
        ]);

        $billing = new BillingService(new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token));
        $result = $billing->createSubscription($shop, $data['plan']);

        return response()->json([
            'confirmation_url' => $result['confirmationUrl'] ?? null,
        ]);
    }
}
