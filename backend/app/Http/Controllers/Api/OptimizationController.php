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

        $optimizations = $shop->optimizations()->with(['backups', 'auditIssue'])->latest()->get()
            ->map(function ($optimization) use ($shop) {
                $data = $optimization->toArray();
                // Both Rollback and Re-apply write to THIS row's own
                // theme_id (AssetBackupService uses the backup's recorded
                // theme_id, never the shop's current target) - flagged
                // whenever that differs from the current target, applied or
                // reverted, so the Optimizations page can warn rather than
                // let either button look like it affects the live storefront
                // when it actually targets a theme nobody's pointed at
                // anymore.
                $data['theme_mismatch'] = $optimization->theme_id !== $shop->target_theme_id;

                return $data;
            });

        return response()->json(['optimizations' => $optimizations]);
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
