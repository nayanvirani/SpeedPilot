<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\ShopInstallation;
use App\Services\Shopify\WebhookVerifier;
use Illuminate\Http\Request;

class AppUninstalledController extends Controller
{
    public function __invoke(Request $request)
    {
        if (! WebhookVerifier::isValid($request->getContent(), $request->header('X-Shopify-Hmac-Sha256', ''))) {
            return response('Invalid signature', 401);
        }

        $shopDomain = $request->header('X-Shopify-Shop-Domain');
        $shop = ShopInstallation::where('shop_domain', $shopDomain)->first();

        if (! $shop) {
            return response('', 200);
        }

        // Shopify Managed Pricing cancels the subscription itself on
        // uninstall - nothing for us to call, just clear our local state.
        $shop->update([
            'access_token' => null,
            'plan' => null,
            'shopify_subscription_id' => null,
            'uninstalled_at' => now(),
        ]);

        return response('', 200);
    }
}
