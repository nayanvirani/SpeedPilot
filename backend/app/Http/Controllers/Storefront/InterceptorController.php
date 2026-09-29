<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\ScriptPageRule;
use App\Models\ShopInstallation;
use App\Services\JsMinifier;
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

        $legacyUrls = $shop->interceptorDelayTargets()->pluck('script_url')->all();
        $pageType = $this->resolvePageType($request->query('page_type'));
        $rulesByApp = $shop->scriptPageRules()->get()->groupBy('app_name');

        $managedGroups = [];
        $legacyTargetUrls = [];

        if ($rulesByApp->isNotEmpty()) {
            // Smart Script Manager rules are keyed by app_name (stable
            // across scans), so they're resolved against this shop's
            // latest audit's app_impacts, not the legacy per-URL list -
            // see ScriptPageRule's migration docblock for why.
            $appImpacts = $shop->latestAudit()?->appImpacts()->where('is_platform', false)->get() ?? collect();

            foreach ($appImpacts as $impact) {
                $urls = array_values(array_filter([$impact->script_url, ...($impact->related_script_urls ?? [])]));
                if (empty($urls)) {
                    continue;
                }

                $appRules = $rulesByApp->get($impact->app_name) ?? collect();
                $rule = $appRules->firstWhere('page_type', $pageType)
                    ?? $appRules->firstWhere('page_type', ScriptPageRule::DEFAULT_PAGE_TYPE);

                if ($rule) {
                    $managedGroups[] = ['urls' => $urls, 'trigger' => $rule->trigger, 'delaySeconds' => $rule->delay_seconds];
                } elseif (! empty(array_intersect($urls, $legacyUrls))) {
                    $legacyTargetUrls = [...$legacyTargetUrls, ...$urls];
                }
            }
        } else {
            $legacyTargetUrls = $legacyUrls;
        }

        $scripts = [];

        if (! empty($managedGroups)) {
            $scripts[] = ScriptImpactActionService::interceptorEngineJsGrouped($managedGroups);
        }

        if (! empty($legacyTargetUrls)) {
            $scripts[] = ScriptImpactActionService::interceptorEngineJs(
                array_values(array_unique($legacyTargetUrls)),
                $shop->interceptor_delay_ms,
                $shop->interceptor_trigger,
            );
        }

        if (empty($scripts)) {
            return $this->jsResponse("console.log('[SpeedPilot] tag loaded, shop active, but no apps currently targeted - no-op');");
        }

        return $this->jsResponse(implode("\n", $scripts));
    }

    /**
     * Shopify's `request.page_type` Liquid values (index/article/blog/...)
     * mapped onto this app's own vocabulary (home/product/collection/cart/
     * search/blog/custom) - the same values PageDiscoveryService assigns
     * when scanning, so a merchant setting a "collection pages" rule in
     * Smart Script Manager matches what they see everywhere else in the app.
     */
    private function resolvePageType(?string $shopifyPageType): string
    {
        return match ($shopifyPageType) {
            'index' => 'home',
            'product' => 'product',
            'collection' => 'collection',
            'cart' => 'cart',
            'search' => 'search',
            'article', 'blog' => 'blog',
            default => 'custom',
        };
    }

    private function jsResponse(string $body): Response
    {
        return response(JsMinifier::minify($body), 200)
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            // Real storefront traffic, fetched on every pageview by every
            // visitor - a short cache still meaningfully cuts origin load
            // across a session's worth of page views without leaving a
            // freshly-toggled delay list stale for long.
            ->header('Cache-Control', 'public, max-age=120');
    }
}
