<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\ShopInstallation;
use App\Services\Shopify\ScriptImpactActionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public, unauthenticated - the one <script src> tag "Advanced delay
 * (experimental)" ever writes into a merchant's theme.liquid points here,
 * and a real storefront visitor's browser fetches it directly, with no App
 * Bridge session to authenticate against. The `t` token identifies the shop
 * (opaque, not the raw domain, so a visitor can't enumerate other shops'
 * delay lists just by guessing).
 *
 * This is the entire reason the engine lives here instead of inline in
 * theme.liquid: the response is generated fresh from this shop's current
 * interceptor_delay_targets on every request, so toggling a delay on/off
 * never needs another theme write, and an inactive/uninstalled shop simply
 * gets a no-op script back - the feature turns off the moment the
 * subscription does, with no separate cleanup step anywhere.
 */
class InterceptorController extends Controller
{
    public function serve(Request $request): Response
    {
        $token = $request->query('t');
        $shop = $token ? ShopInstallation::where('interceptor_token', $token)->first() : null;

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
