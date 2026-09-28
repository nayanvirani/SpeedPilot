<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\ShopInstallation;
use Illuminate\Http\Request;

class AppUninstalledController extends Controller
{
    public function __invoke(Request $request)
    {
        $shopDomain = $request->header('X-Shopify-Shop-Domain');
        $shop = ShopInstallation::where('shop_domain', $shopDomain)->first();

        if (! $shop) {
            return response('', 200);
        }

        // access_token is genuinely dead the moment the shop uninstalls -
        // clearing it is a real security measure, not just bookkeeping.
        //
        // plan/shopify_subscription_id/plan_expires_at are deliberately
        // left alone: Shopify Managed Pricing cancels the subscription
        // itself around the same time (its own app_subscriptions/update
        // webhook updates the Subscription history correctly, preserving
        // plan_expires_at - see BillingService::applyPlan()), and a
        // merchant who reinstalls before what they already paid for
        // expires should get their access back immediately, not be forced
        // through a fresh checkout for time they've already paid for.
        // This data only ever fully disappears via the separate, mandatory
        // shop/redact GDPR webhook (GdprController::shopRedact(), fired 48
        // hours after uninstall) - that hard-deletes the whole row, which
        // is the actual, legally-required point of no return here, not
        // this webhook.
        $shop->update([
            'access_token' => null,
            'uninstalled_at' => now(),
        ]);

        return response('', 200);
    }
}
