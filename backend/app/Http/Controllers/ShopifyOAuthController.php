<?php

namespace App\Http\Controllers;

use App\Models\ShopInstallation;
use App\Services\Shopify\ShopifyOAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ShopifyOAuthController extends Controller
{
    public function install(Request $request, ShopifyOAuthService $oauth)
    {
        $shop = $request->query('shop', '');

        if (! $oauth->isValidShopDomain($shop)) {
            return response('Invalid shop parameter', 400);
        }

        $state = Str::random(40);
        session(['shopify_oauth_state' => $state]);

        return redirect($oauth->buildInstallUrl($shop, $state));
    }

    public function callback(Request $request, ShopifyOAuthService $oauth)
    {
        $shop = $request->query('shop', '');

        if (! $oauth->isValidShopDomain($shop) || ! $oauth->verifyHmac($request->query())) {
            return response('Invalid request', 400);
        }

        if ($request->query('state') !== session('shopify_oauth_state')) {
            return response('State mismatch', 400);
        }

        $tokenData = $oauth->exchangeCodeForToken($shop, $request->query('code'));

        ShopInstallation::updateOrCreate(
            ['shop_domain' => $shop],
            [
                'access_token' => $tokenData['access_token'],
                'access_token_expires_at' => isset($tokenData['expires_in'])
                    ? now()->addSeconds((int) $tokenData['expires_in'])
                    : null,
                'scope' => $tokenData['scope'] ?? null,
                'installed_at' => now(),
                'uninstalled_at' => null,
            ],
        );

        // With Shopify Managed Pricing, the merchant already picked a plan
        // as part of Shopify's own install flow, before this callback ever
        // ran - the app_subscriptions/update webhook (fired around the same
        // time) is what actually syncs it, not a query here.
        //
        // Redirect into Shopify's own admin URL for the app, not our raw
        // frontend URL directly - Shopify then loads application_url
        // embedded in its iframe/top bar as normal. Redirecting straight to
        // our own domain would break out of the embedded context entirely.
        $shopHandle = str_replace('.myshopify.com', '', $shop);

        return redirect("https://admin.shopify.com/store/{$shopHandle}/apps/".config('shopify.api_key'));
    }
}
