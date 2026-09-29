<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppImpact;
use App\Models\ShopInstallation;
use App\Services\Scanner\ScannerClient;
use App\Services\Shopify\AppCategoryClassifier;
use App\Services\Shopify\AssetBackupService;
use App\Services\Shopify\ScriptImpactActionService;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\ThemeAssetLocatorService;
use App\Services\Shopify\ThemeAssetService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The app/script impact table - the spec's core differentiator. Lets the
 * merchant act per-row: Disable / Delay / Exclude, backed by a real theme
 * edit (ScriptImpactActionService) when the script can be located in the
 * theme's own files, with rollback via the same asset-backup mechanism the
 * safe auto-fixes use. A script injected by another app via Shopify's
 * Script Tag API can't be edited this way - that's reported honestly
 * instead of the status silently claiming an effect that didn't happen.
 */
class AppImpactController extends Controller
{
    public function index(Request $request, AppCategoryClassifier $classifier)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $latestAudit = $shop->latestAudit();
        $impacts = $latestAudit?->appImpacts ?? collect();

        // Grouped by our own curated category list, not Lighthouse's own
        // third-party-web categorization - that dataset is built for
        // general web analytics/ads and doesn't reliably know Shopify-
        // specific apps like Judge.me or Loox, so it would either miss real
        // overlaps or mis-bucket apps it doesn't recognize. classify()
        // stays silent (null) on anything not on the curated list rather
        // than guessing.
        $byCategory = $impacts
            ->reject(fn (AppImpact $impact) => $impact->is_platform)
            ->map(fn (AppImpact $impact) => [$impact, $classifier->classify($impact->app_name)])
            ->filter(fn (array $pair) => $pair[1] !== null)
            ->groupBy(fn (array $pair) => $pair[1]);

        $overlapNamesByImpactId = [];

        foreach ($byCategory as $category => $pairs) {
            if ($pairs->count() < 2) {
                continue;
            }

            $names = $pairs->map(fn (array $pair) => $pair[0]->app_name)->all();

            foreach ($pairs as [$impact]) {
                $overlapNamesByImpactId[$impact->id] = [
                    'category' => $category,
                    'with' => array_values(array_diff($names, [$impact->app_name])),
                ];
            }
        }

        $decorated = $impacts->map(function (AppImpact $impact) use ($overlapNamesByImpactId) {
            $data = $impact->toArray();
            $overlap = $overlapNamesByImpactId[$impact->id] ?? null;
            $data['overlap_category'] = $overlap['category'] ?? null;
            $data['overlap_with'] = $overlap['with'] ?? [];

            return $data;
        });

        return response()->json([
            'app_impacts' => $decorated,
        ]);
    }

    public function updateStatus(Request $request, int $id)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'disabled', 'delayed', 'excluded'])],
            // Explicit 'interceptor'/'content_replace' bypass the theme-file
            // locate attempt entirely - omitted (the default), 'delayed'
            // keeps today's theme-file-only behavior unchanged, still
            // failing honestly when the script isn't found. 'content_replace'
            // ("Stop (verified)") only succeeds when this scan actually
            // observed the script as literal text in content_for_header.
            'method' => ['nullable', Rule::in(['theme_edit', 'interceptor', 'content_replace'])],
        ]);

        $impact = AppImpact::whereHas(
            'audit',
            fn ($q) => $q->where('shop_installation_id', $shop->id)
        )->findOrFail($id);

        // Shopify's own platform scripts (Shop Pay, checkout, core
        // analytics) are injected by Shopify itself, never present as
        // literal text in the theme's own files - disabling/delaying them
        // isn't something any app can do, or should offer, so this is
        // refused upfront rather than always failing after a futile search.
        if ($impact->is_platform && in_array($data['status'], ['disabled', 'delayed'], true)) {
            return response()->json([
                'app_impact' => $impact,
                'applied' => false,
                'message' => "This is loaded directly by Shopify's platform, not an installed app - it "
                    ."can't be disabled or delayed by any app, including this one. Use \"Excluded\" to ".
                    'hide it from this list instead.',
            ]);
        }

        $actions = new ScriptImpactActionService(
            $themeAssets = new ThemeAssetService(new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token)),
            new ThemeAssetLocatorService($themeAssets),
            new AssetBackupService,
        );

        $useInterceptor = ($data['method'] ?? null) === 'interceptor';
        $useContentReplace = ($data['method'] ?? null) === 'content_replace';

        $result = match (true) {
            $data['status'] === 'disabled' => $actions->disable($shop, $impact),
            $data['status'] === 'delayed' && $useInterceptor => $actions->interceptorDelay($shop, $impact),
            $data['status'] === 'delayed' && $useContentReplace => $actions->stopContentMatch($shop, $impact),
            $data['status'] === 'delayed' => $actions->delay($shop, $impact),
            $data['status'] === 'active' => $actions->restore($shop, $impact),
            // "Excluded" is a dismiss-only action - it stops this script
            // from being flagged in the impact report without touching the
            // storefront, unlike disable/delay which edit the live theme.
            default => ['applied' => true, 'message' => null],
        };

        if ($result['applied']) {
            $impact->update([
                'status' => $data['status'],
                'delay_method' => $data['status'] === 'delayed'
                    ? ($useInterceptor ? 'interceptor' : ($useContentReplace ? 'content_replace' : 'theme_edit'))
                    : null,
            ]);
        }

        return response()->json([
            'app_impact' => $impact->fresh(),
            'applied' => $result['applied'],
            'message' => $result['message'],
        ]);
    }

    /**
     * "Auto-fix" half of Advanced Delay's setup - one global tag, not
     * per-app, so this deliberately takes no impact ID (see
     * ScriptImpactActionService::autoInstallInterceptorTag()).
     */
    public function installInterceptorTag(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $actions = new ScriptImpactActionService(
            $themeAssets = new ThemeAssetService(new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token)),
            new ThemeAssetLocatorService($themeAssets),
            new AssetBackupService,
        );

        $result = $actions->autoInstallInterceptorTag($shop);

        return response()->json($result);
    }

    /**
     * "What would my score be without this app" - answered with a real
     * second scan, the app's own URLs blocked via Lighthouse's native
     * blockedUrlPatterns option (confirmed via Lighthouse's own docs/search
     * - not a custom hack), never a theme write. The block only exists
     * inside that one throwaway browser session - the live theme, and every
     * other visitor's page load, is completely unaffected. Not persisted as
     * an Audit row: this is a what-if comparison, not a real monitored data
     * point, so it stays out of the Monitoring trend history.
     */
    public function projectRemoval(Request $request, int $id, ScannerClient $scanner)
    {
        // Two full scans back-to-back (each with its own retry budget) is
        // longer-running than any other synchronous action in this app -
        // explicit headroom rather than relying on PHP's default limit.
        set_time_limit(300);

        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $impact = AppImpact::whereHas(
            'audit',
            fn ($q) => $q->where('shop_installation_id', $shop->id)
        )->findOrFail($id);

        if ($impact->is_platform) {
            return response()->json([
                'error' => "This is loaded directly by Shopify's platform - blocking it would risk breaking "
                    ."checkout or core storefront functionality, not just remove an app, so a projection isn't "
                    .'offered for it.',
            ], 422);
        }

        $urls = array_filter(array_merge([$impact->script_url], $impact->related_script_urls ?? []));

        if (empty($urls)) {
            return response()->json(['error' => 'No script URL was recorded for this app to project.'], 422);
        }

        // Host-level wildcard, not the full URL - Lighthouse's own pattern
        // matching is a simple substring/wildcard match (confirmed via
        // search, not guessed), and blocking by host reliably catches every
        // request this app makes even if it loads several files from the
        // same domain with URLs that vary run to run (cache-busting query
        // params, versioned paths).
        $patterns = collect($urls)
            ->map(fn (string $url) => parse_url($url, PHP_URL_HOST))
            ->filter()
            ->unique()
            ->map(fn (string $host) => "*{$host}*")
            ->values()
            ->all();

        $homepageUrl = "https://{$shop->shop_domain}";

        try {
            $current = $scanner->scan($homepageUrl, $shop->storefront_password, 'mobile');
            $projected = $scanner->scan($homepageUrl, $shop->storefront_password, 'mobile', $patterns);
        } catch (Throwable $e) {
            return response()->json(['error' => "Couldn't run the projection scan right now: ".$e->getMessage()], 502);
        }

        $currentScore = $current['score'] ?? null;
        $projectedScore = $projected['score'] ?? null;

        return response()->json([
            'current_score' => $currentScore,
            'projected_score' => $projectedScore,
            'delta' => ($currentScore !== null && $projectedScore !== null) ? $projectedScore - $currentScore : null,
        ]);
    }
}
