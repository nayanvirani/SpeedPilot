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
        ]);
    }

    public function updateStatus(Request $request, int $id)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'disabled', 'delayed', 'excluded'])],
        ]);

        $impact = AppImpact::whereHas(
            'audit',
            fn ($q) => $q->where('shop_installation_id', $shop->id)
        )->findOrFail($id);

        $actions = new ScriptImpactActionService(
            $themeAssets = new ThemeAssetService(new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token)),
            new ThemeAssetLocatorService($themeAssets),
            new AssetBackupService,
        );

        $result = match ($data['status']) {
            'disabled' => $actions->disable($shop, $impact),
            'delayed' => $actions->delay($shop, $impact),
            'active' => $actions->restore($shop, $impact),
            // "Excluded" is a dismiss-only action - it stops this script
            // from being flagged in the impact report without touching the
            // storefront, unlike disable/delay which edit the live theme.
            'excluded' => ['applied' => true, 'message' => null],
        };

        if ($result['applied']) {
            $impact->update(['status' => $data['status']]);
        }

        return response()->json([
            'app_impact' => $impact->fresh(),
            'applied' => $result['applied'],
            'message' => $result['message'],
        ]);
    }
}
