<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\ShopInstallation;
use App\Services\Shopify\BillingService;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\WebhookVerifier;
use Illuminate\Http\Request;

/**
 * Fires whenever a merchant's Shopify Managed Pricing subscription changes
 * (upgrade, downgrade, cancellation). We don't trust the webhook payload's
 * plan details directly - just use it as a signal to re-query
 * activeSubscriptions, which is the same sync path OAuth callback uses.
 */
class AppSubscriptionsUpdateController extends Controller
{
    public function __invoke(Request $request)
    {
        if (! WebhookVerifier::isValid($request->getContent(), $request->header('X-Shopify-Hmac-Sha256', ''))) {
            return response('Invalid signature', 401);
        }

        $shopDomain = $request->header('X-Shopify-Shop-Domain');
        $shop = ShopInstallation::where('shop_domain', $shopDomain)->whereNull('uninstalled_at')->first();

        if ($shop && $shop->access_token) {
            (new BillingService(new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token)))
                ->syncActivePlan($shop);
        }

        return response('', 200);
    }
}
