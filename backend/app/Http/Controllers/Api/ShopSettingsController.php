<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OptimizedTheme;
use App\Models\ShopInstallation;
use App\Services\Scanner\StorefrontAccessChecker;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\ThemeAssetService;
use App\Services\Shopify\ThemeDuplicateService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShopSettingsController extends Controller
{
    public function show(Request $request, StorefrontAccessChecker $storefrontAccess)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $themeDiverged = null;
        $storefrontLocked = $storefrontAccess->blocksScan($shop);

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
            'storefront_locked' => $storefrontLocked,
            'target_theme_id' => $shop->target_theme_id,
            'target_theme_mode' => $shop->target_theme_mode,
            'theme_diverged' => $themeDiverged,
            'scan_frequency' => $shop->scan_frequency,
            'scan_devices' => $shop->scan_devices,
            'has_slack_webhook' => ! empty($shop->slack_webhook_url),
            'interceptor_delay_ms' => $shop->interceptor_delay_ms,
            'interceptor_trigger' => $shop->interceptor_trigger,
            'avg_order_value' => $shop->avg_order_value,
            'monthly_orders' => $shop->monthly_orders,
            'speed_budget_lcp_seconds' => $shop->speed_budget_lcp_seconds,
            // Shopify gates actually writing theme files behind a separate,
            // app-level "protected scope" exemption it grants (or doesn't) -
            // this is never a per-merchant setting, and no button in this
            // app can fix it. Surfaced so the merchant (and whoever's
            // explaining this app to them) can tell "blocked because
            // Shopify hasn't approved this yet" apart from "broken."
            'theme_write_blocked_at' => $shop->theme_write_blocked_at,
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

    /**
     * "Advanced delay (experimental)"'s release timing - was hardcoded
     * (5s, first interaction) with no way for a merchant to see or change
     * it. delay_ms always applies as the hard fallback regardless of
     * trigger, so a merchant picking e.g. window_load isn't stuck forever
     * if that event never fires for some reason.
     */
    public function updateInterceptorTiming(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'interceptor_delay_ms' => 'required|integer|min:500|max:30000',
            'interceptor_trigger' => ['required', Rule::in(['interaction', 'window_load', 'document_load', 'timeout_only'])],
        ]);

        $shop->update($data);

        return response()->json([
            'interceptor_delay_ms' => $shop->interceptor_delay_ms,
            'interceptor_trigger' => $shop->interceptor_trigger,
        ]);
    }

    /**
     * Optional inputs for the Dashboard's revenue-impact estimate
     * (MonitoringController::beforeAfter()) - both nullable/clearable, since
     * showing a percentage-only estimate is honest and a fabricated dollar
     * figure from guessed traffic/AOV is not.
     */
    public function updateRevenueInputs(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'avg_order_value' => 'nullable|numeric|min:0|max:999999.99',
            'monthly_orders' => 'nullable|integer|min:0|max:10000000',
        ]);

        $shop->update([
            'avg_order_value' => $data['avg_order_value'] ?? null,
            'monthly_orders' => $data['monthly_orders'] ?? null,
        ]);

        return response()->json([
            'avg_order_value' => $shop->avg_order_value,
            'monthly_orders' => $shop->monthly_orders,
        ]);
    }

    /**
     * A merchant-set target max LCP - null disables it entirely.
     * MonitoringRecorder alerts on the transition into/out of breach, using
     * speed_budget_breached_at the same way the existing regression/recovery
     * pair already tracks state.
     */
    public function updateSpeedBudget(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'speed_budget_lcp_seconds' => 'nullable|numeric|min:0.1|max:60',
        ]);

        $newBudget = $data['speed_budget_lcp_seconds'] ?? null;
        $latestLcp = $shop->audits()->where('status', 'complete')->latest('created_at')->value('lcp');

        // Clearing the budget, or raising it past the current LCP, should
        // also clear any standing breach flag - otherwise a merchant who
        // loosens their budget would never get a "back within budget"
        // message later, since the flag would still reflect the old,
        // stricter target instead of the one actually in effect now.
        $stillBreached = $newBudget !== null && $latestLcp !== null && (float) $latestLcp > $newBudget;

        $shop->update([
            'speed_budget_lcp_seconds' => $newBudget,
            'speed_budget_breached_at' => $stillBreached ? $shop->speed_budget_breached_at : null,
        ]);

        return response()->json(['speed_budget_lcp_seconds' => $shop->speed_budget_lcp_seconds]);
    }

    /**
     * Spec 4.20 notifications, Slack-only (Railway can't send outbound
     * email) - a regression alert or "fixes applied" notice posts here via
     * SlackNotifier whenever the merchant has set a webhook.
     */
    public function updateSlackWebhook(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'webhook_url' => 'nullable|url|starts_with:https://hooks.slack.com/',
        ]);

        $shop->update(['slack_webhook_url' => ($data['webhook_url'] ?? null) ?: null]);

        return response()->json(['has_slack_webhook' => ! empty($shop->slack_webhook_url)]);
    }

    public function updateStorefrontPassword(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'password' => 'nullable|string|max:255',
        ]);
        $password = $data['password'] ?? null;

        // Saving a (new) password is an explicit "try this" signal - clear
        // any existing lock so the next scan actually attempts it, instead
        // of blocksScan() staying stuck on a stale lock from before this
        // password existed. RunAuditJob re-flags it the moment a real scan
        // proves this password wrong too, so nothing is trusted blindly.
        $shop->update([
            'storefront_password' => $password ?: null,
            'storefront_locked_at' => $password ? null : $shop->storefront_locked_at,
        ]);

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
