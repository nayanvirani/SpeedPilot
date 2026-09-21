<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppImpact;
use App\Models\AuditIssue;
use App\Models\ShopInstallation;
use App\Services\Shopify\AssetBackupService;
use App\Services\Shopify\ImageLazyLoadSweeper;
use App\Services\Shopify\MediumFixService;
use App\Services\Shopify\SafeFixCodeService;
use App\Services\Shopify\ScriptImpactActionService;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\ThemeAssetLocatorService;
use App\Services\Shopify\ThemeAssetService;
use App\Services\Shopify\ThemeWriteAccessDeniedException;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The "manual fix" half of every fix in the app: computes the exact same
 * edit the auto-fix path would make and hands it back as copy-paste-able
 * code, without ever calling a write-scoped Shopify API. Exists alongside
 * (not instead of) the auto-fix buttons so a merchant can choose per fix -
 * some want SpeedPilot to just do it, others want to review and paste it
 * into Shopify's own code editor themselves.
 */
class FixCodeController extends Controller
{
    public function forIssue(Request $request, int $issueId)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $issue = AuditIssue::whereHas('audit', fn ($q) => $q->where('shop_installation_id', $shop->id))
            ->findOrFail($issueId);

        $client = new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token);
        $themeAssets = new ThemeAssetService($client);
        $locator = new ThemeAssetLocatorService($themeAssets);

        try {
            if ($issue->risk_tier === 'medium') {
                $service = new MediumFixService($themeAssets, $locator, new AssetBackupService);
                $preview = $service->preview($issue, $shop);

                return response()->json(['code' => [
                    'fix_type' => 'minify_css',
                    'files' => [['asset_key' => $preview['asset_key'], 'original' => $preview['original_content'], 'fixed' => $preview['minified_content']]],
                    'truncated_count' => 0,
                ]]);
            }

            $service = new SafeFixCodeService($themeAssets, $locator, new ImageLazyLoadSweeper($themeAssets));

            return response()->json(['code' => $service->code($issue)]);
        } catch (ThemeWriteAccessDeniedException) {
            // code() only reads, never writes - this can't actually happen
            // here, but the theme-read GraphQL calls it shares a client
            // with write calls, so handle it defensively rather than assume.
            return response()->json(['error' => 'Could not read your theme right now.'], 503);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function forAppImpact(Request $request, int $impactId)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $data = $request->validate(['action' => 'required|in:disabled,delayed']);

        $impact = AppImpact::whereHas('audit', fn ($q) => $q->where('shop_installation_id', $shop->id))
            ->findOrFail($impactId);

        if ($impact->is_platform) {
            return response()->json([
                'error' => "This is loaded directly by Shopify's platform, not an installed app - there's ".
                    'no theme code to show, since it was never in the theme to begin with.',
            ], 422);
        }

        $client = new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token);
        $themeAssets = new ThemeAssetService($client);
        $locator = new ThemeAssetLocatorService($themeAssets);
        $service = new ScriptImpactActionService($themeAssets, $locator, new AssetBackupService);

        $result = $service->preview($shop, $impact, $data['action']);

        if ($result['error'] !== null) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json(['code' => [
            'fix_type' => $data['action'],
            'files' => [['asset_key' => $result['asset_key'], 'original' => $result['original'], 'fixed' => $result['fixed']]],
            'truncated_count' => 0,
        ]]);
    }
}
