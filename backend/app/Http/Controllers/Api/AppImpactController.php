<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppImpact;
use App\Models\ShopInstallation;
use App\Services\Shopify\AssetBackupService;
use App\Services\Shopify\ScriptImpactActionService;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\ThemeAssetLocatorService;
use App\Services\Shopify\ThemeAssetService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
    public function index(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $latestAudit = $shop->latestAudit();

        return response()->json([
            'app_impacts' => $latestAudit?->appImpacts ?? [],
            'advanced_delay_embed_url' => ScriptImpactActionService::interceptorEmbedDeepLink($shop),
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
            'embed_url' => $useInterceptor ? ScriptImpactActionService::interceptorEmbedDeepLink($shop) : null,
        ]);
    }
}
