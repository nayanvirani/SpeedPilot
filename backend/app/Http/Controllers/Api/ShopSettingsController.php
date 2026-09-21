<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OptimizedTheme;
use App\Models\ShopInstallation;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\ThemeAssetService;
use App\Services\Shopify\ThemeDuplicateService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShopSettingsController extends Controller
{
    public function show(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $themeDiverged = null;

        if ($shop->target_theme_mode === 'duplicate' && $shop->target_theme_id) {
            $optimizedTheme = OptimizedTheme::where('shop_installation_id', $shop->id)
                ->where('duplicate_theme_id', $shop->target_theme_id)
                ->first();

            if ($optimizedTheme) {
                $duplicator = new ThemeDuplicateService(new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token));
                $themeDiverged = $duplicator->checkDivergence($optimizedTheme, persist: false);
            }
        }

        return response()->json([
            'has_storefront_password' => ! empty($shop->storefront_password),
            'target_theme_id' => $shop->target_theme_id,
            'target_theme_mode' => $shop->target_theme_mode,
            'theme_diverged' => $themeDiverged,
            'scan_frequency' => $shop->scan_frequency,
            'scan_devices' => $shop->scan_devices,
        ]);
    }

    /**
     * scan_frequency only affects RunMonitoringJob's scheduled re-scans - an
     * on-demand "Scan My Store" click always runs immediately either way.
     */
    public function updateScanPreferences(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'scan_frequency' => ['required', Rule::in(['daily', 'weekly'])],
            'scan_devices' => ['required', Rule::in(['both', 'mobile', 'desktop'])],
        ]);

        $shop->update($data);

        return response()->json([
            'scan_frequency' => $shop->scan_frequency,
            'scan_devices' => $shop->scan_devices,
        ]);
    }

    public function updateStorefrontPassword(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'password' => 'nullable|string|max:255',
        ]);

        $shop->update(['storefront_password' => $data['password'] ?: null]);

        return response()->json(['has_storefront_password' => ! empty($shop->storefront_password)]);
    }

    /**
     * Every theme-writing action (safe auto-fixes, App Impact disable/delay)
     * refuses to touch anything until this is set - no fix silently defaults
     * to whatever Shopify reports as the live theme.
     */
    public function listThemes(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $themeAssets = new ThemeAssetService(new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token));

        return response()->json(['themes' => $themeAssets->listThemes()]);
    }

    /**
     * The merchant picks any theme from their own store - their live theme
     * for immediate effect, or one they've already duplicated themselves in
     * Shopify admin (Online Store > Themes > Duplicate) for a safe preview.
     * SpeedPilot never creates a theme on their behalf; mode is derived from
     * the selected theme's own role, not a separate choice.
     */
    public function updateTargetTheme(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'theme_id' => 'required|string',
        ]);

        $themeAssets = new ThemeAssetService(new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token));
        $theme = collect($themeAssets->listThemes())->firstWhere('id', $data['theme_id']);

        if (! $theme) {
            return response()->json(['error' => 'Could not find that theme - it may have been deleted.'], 422);
        }

        if ($theme['role'] === 'main') {
            $shop->update([
                'target_theme_id' => $theme['id'],
                'target_theme_mode' => 'live',
            ]);
        } else {
            $liveThemeId = $themeAssets->activeThemeId();

            if (! $liveThemeId) {
                return response()->json(['error' => 'Could not access your theme right now.'], 422);
            }

            $duplicator = new ThemeDuplicateService(new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token));
            $duplicator->trackPreviewTheme($shop, $liveThemeId, $theme['id']);

            $shop->update([
                'target_theme_id' => $theme['id'],
                'target_theme_mode' => 'duplicate',
            ]);
        }

        return response()->json([
            'target_theme_id' => $shop->target_theme_id,
            'target_theme_mode' => $shop->target_theme_mode,
        ]);
    }
}
