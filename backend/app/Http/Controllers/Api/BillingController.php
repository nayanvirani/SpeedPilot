<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopInstallation;
use App\Services\Shopify\BillingService;
use App\Services\Shopify\ShopifyGraphQLClient;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    public function plans()
    {
        return response()->json(['plans' => config('speedpilot.plans')]);
    }

    public function subscribe(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'plan' => ['required', Rule::in(['starter', 'pro'])],
        ]);

        $billing = new BillingService(new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token));
        $result = $billing->createSubscription($shop, $data['plan']);

        return response()->json([
            'confirmation_url' => $result['confirmationUrl'] ?? null,
        ]);
    }
}
