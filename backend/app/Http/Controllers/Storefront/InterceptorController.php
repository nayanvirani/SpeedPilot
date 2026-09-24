<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\ShopInstallation;
use App\Services\Shopify\ScriptImpactActionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public, unauthenticated - the one <script src> tag "Advanced delay
 * (experimental)" ever needs points here, and a real storefront visitor's
 * browser fetches it directly, with no App Bridge session to authenticate
 * against. SpeedPilot never writes this tag into the merchant's theme
 * itself (ScriptImpactActionService::interceptorManualSnippet() hands it
 * back as copy-paste code instead, the same "Manual fix" pattern every
 * other action in this app uses) - the shop is resolved from the plain
 * `shop` domain query param the pasted tag's Liquid resolves at render time
 * (public knowledge - it's the storefront's own URL - same as
 * RumEventController already trusts). `t` is kept only as a fallback for
 * any tag pasted in during an earlier version of this feature that used an
 * opaque token instead.
 *
 * The response is generated fresh from this shop's current
 * interceptor_delay_targets on every request, so toggling a delay on/off
 * never needs the merchant to touch their theme again, and an inactive/
 * uninstalled shop simply gets a no-op script back - the feature turns off
 * the moment the subscription does, with no separate cleanup step needed.
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
            // Still logs - a merchant checking "is the tag even loading"
            // shouldn't see silence just because there's nothing to do
            // right now (unresolved shop, or an inactive/uninstalled one).
            return $this->jsResponse("console.log('[SpeedPilot] tag loaded, but shop not found or inactive - no-op');");
        }

        $urls = $shop->interceptorDelayTargets()->pluck('script_url')->all();

        if (empty($urls)) {
            return $this->jsResponse("console.log('[SpeedPilot] tag loaded, shop active, but no apps currently targeted for Advanced Delay - no-op');");
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
