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
                'scope' => $tokenData['scope'] ?? null,
                'installed_at' => now(),
                'uninstalled_at' => null,
            ],
        );

        return redirect(rtrim(config('shopify.frontend_url'), '/')."?shop={$shop}");
    }
}
