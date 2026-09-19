<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\ShopInstallation;
use App\Services\Shopify\BillingService;
use Illuminate\Http\Request;

/**
 * Fires on every Shopify Managed Pricing subscription status change
 * (created, active, cancelled, frozen, ...) for a plan picked entirely on
 * Shopify's own hosted page - this is the only place the app learns a
 * subscription actually happened. The payload itself carries everything
 * needed (name, status, charge id), so no follow-up API call is made.
 * HMAC verification and dedup happen in the shopify.webhook middleware.
 */
class AppSubscriptionsUpdateController extends Controller
{
    public function __invoke(Request $request, BillingService $billing)
    {
        $shopDomain = $request->header('X-Shopify-Shop-Domain');
        $shop = ShopInstallation::where('shop_domain', $shopDomain)->whereNull('uninstalled_at')->first();

        if ($shop) {
            $billing->syncFromWebhookPayload($shop, $request->json()->all());
        }

        return response('', 200);
    }
}
