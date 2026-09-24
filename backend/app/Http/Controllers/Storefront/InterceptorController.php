<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\ShopInstallation;
use App\Services\Shopify\ScriptImpactActionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public, unauthenticated - the <script src> tag pointing here is only ever
 * present on a storefront when the merchant has switched on the "SpeedPilot
 * Advanced Delay" app embed in Theme Editor (extensions/rum-snippet/blocks/
 * advanced-delay-snippet.liquid), same mechanism as the RUM web-vitals
 * snippet - never a manual paste into theme.liquid, and never present at
 * all unless that embed is on. A real storefront visitor's browser fetches
 * it directly, with no App Bridge session to authenticate against, so the
 * shop is resolved from the plain `shop` domain the embed passes (public
 * knowledge - it's the storefront's own URL - same as RumEventController
 * already trusts). `t` is kept only as a fallback for any tag pasted in
 * during the earlier theme-write-based version of this feature.
 *
 * The response is generated fresh from this shop's current
 * interceptor_delay_targets on every request, so toggling a delay on/off
 * never needs another theme write, and an inactive/uninstalled shop simply
 * gets a no-op script back - the feature turns off the moment the
 * subscription does, with no separate cleanup step anywhere. Turning the
 * app embed off in Theme Editor removes the tag entirely, which is an even
 * more immediate kill switch than the DB-side check below.
 */
class InterceptorController extends Controller
{
    public function serve(Request $request): Response
    {
        $shopDomain = $request->query('shop');
        $token = $request->query('t');

        $shop = match (true) {
            $shopDomain !== null => ShopInstallation::where('shop_domain', $shopDomain)->whereNull('uninstalled_at')->first(),
            $token !== null => ShopInstallation::where('interceptor_token', $token)->first(),
            default => null,
        };

        if (! $shop || ! $shop->isActive()) {
            return $this->jsResponse('// SpeedPilot: inactive');
        }

        $urls = $shop->interceptorDelayTargets()->pluck('script_url')->all();

        if (empty($urls)) {
            return $this->jsResponse('// SpeedPilot: nothing to delay right now');
        }

        return $this->jsResponse(ScriptImpactActionService::interceptorEngineJs($urls));
    }

    private function jsResponse(string $body): Response
    {
        return response($body, 200)
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            // Real storefront traffic, fetched on every pageview by every
            // visitor - a short cache still meaningfully cuts origin load
            // across a session's worth of page views without leaving a
            // freshly-toggled delay list stale for long.
            ->header('Cache-Control', 'public, max-age=120');
    }
}
