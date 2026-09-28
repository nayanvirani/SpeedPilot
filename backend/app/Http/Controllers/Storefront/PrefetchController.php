<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\ShopInstallation;
use App\Services\Shopify\ScriptImpactActionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public, unauthenticated - same shape as InterceptorController, mirrored
 * for the "Instant navigation" tag. Unlike the interceptor script, this one
 * has no per-shop configuration to bake in (no target list, no delay
 * timing) - the only per-shop check is whether the shop is still active, so
 * the script itself is a fixed constant, not regenerated per request.
 */
class PrefetchController extends Controller
{
    public function serve(Request $request): Response
    {
        $shopDomain = $request->query('shop');
        $shop = $shopDomain !== null
            ? ShopInstallation::where('shop_domain', $shopDomain)->whereNull('uninstalled_at')->first()
            : null;

        if (! $shop || ! $shop->isActive()) {
            return $this->jsResponse("console.log('[SpeedPilot] instant-navigation tag loaded, but shop not found or inactive - no-op');");
        }

        return $this->jsResponse(ScriptImpactActionService::prefetchEngineJs());
    }

    private function jsResponse(string $body): Response
    {
        return response($body, 200)
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=120');
    }
}
