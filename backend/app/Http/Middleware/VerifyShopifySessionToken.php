<?php

namespace App\Http\Middleware;

use App\Models\ShopInstallation;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the App Bridge session token the Remix frontend forwards as a
 * Bearer header, then resolves it to a shop_installations row on the request
 * so controllers never touch raw JWTs.
 */
class VerifyShopifySessionToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['error' => 'Missing session token'], 401);
        }

        try {
            $payload = JWT::decode($token, new Key(config('shopify.api_secret'), 'HS256'));
        } catch (\Throwable) {
            return response()->json(['error' => 'Invalid session token'], 401);
        }

        // dest looks like "https://{shop}.myshopify.com"
        $shopDomain = parse_url($payload->dest ?? '', PHP_URL_HOST);

        $shop = ShopInstallation::where('shop_domain', $shopDomain)->first();

        if (! $shop || ! $shop->isActive()) {
            return response()->json(['error' => 'Unknown or uninstalled shop'], 401);
        }

        $request->attributes->set('shop', $shop);

        return $next($request);
    }
}
