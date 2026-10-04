<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Optimization;
use App\Models\ShopInstallation;
use App\Services\PlanPolicy;
use App\Services\Shopify\AssetBackupService;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\ThemeAssetService;
use Illuminate\Http\Request;

class OptimizationController extends Controller
{
    public function index(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        return response()->json([
            'optimizations' => $shop->optimizations()->with(['backups', 'auditIssue'])->latest()->get(),
        ]);
    }

    public function rollback(Request $request, int $id, AssetBackupService $backups)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $optimization = Optimization::where('shop_installation_id', $shop->id)->findOrFail($id);

        $themeAssets = new ThemeAssetService(
            new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token)
        );

        $restored = $backups->restore($optimization, $themeAssets);

        if (! $restored) {
            return response()->json(['error' => 'Nothing to roll back'], 422);
        }

        return response()->json(['optimization' => $optimization->refresh()]);
    }

    public function reapply(Request $request, int $id, AssetBackupService $backups)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        if (! (new PlanPolicy($shop))->hasPaidPlan()) {
            return response()->json(['error' => 'Re-applying a fix is not available on the Free plan.'], 403);
        }

        $optimization = Optimization::where('shop_installation_id', $shop->id)->findOrFail($id);

        $themeAssets = new ThemeAssetService(
            new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token)
        );

        $reapplied = $backups->reapply($optimization, $themeAssets);

        if (! $reapplied) {
            return response()->json(['error' => 'Nothing to re-apply'], 422);
        }

        return response()->json(['optimization' => $optimization->refresh()]);
    }
}
