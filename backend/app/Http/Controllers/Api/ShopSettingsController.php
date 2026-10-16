<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OptimizedTheme;
use App\Models\ShopInstallation;
use App\Services\PlanPolicy;
use App\Services\Scanner\StorefrontAccessChecker;
use App\Services\Shopify\AssetBackupService;
use App\Services\Shopify\ScriptImpactActionService;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\ThemeAssetLocatorService;
use App\Services\Shopify\ThemeAssetService;
use App\Services\Shopify\ThemeDuplicateService;
use App\Services\Shopify\ThemeWriteAccessDeniedException;
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
            'scan_devices' => $shop->scan_devices,
            'has_slack_webhook' => ! empty($shop->slack_webhook_url),
            'interceptor_delay_ms' => $shop->interceptor_delay_ms,
            'interceptor_trigger' => $shop->interceptor_trigger,
            'avg_order_value' => $shop->avg_order_value,
            'monthly_orders' => $shop->monthly_orders,
            'speed_budget_lcp_seconds' => $shop->speed_budget_lcp_seconds,
            'has_prefetch_tag' => $shop->optimizations()->where('type', 'prefetch_install')->where('status', 'applied')->exists(),
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
     * Frequency is no longer merchant-configurable (the scan_frequency
     * column was dropped) - automatic monitoring re-scans are always weekly
     * now for every shop (daily was real scanner compute cost - a full
     * Playwright+Lighthouse run per shop per day - that wasn't sustainable
     * to offer; see RunMonitoringJob). A manual "Scan My Store" click is
     * unaffected either way - that's its own, separate cooldown
     * (PlanPolicy::manualScanCooldownDays()).
     */
    public function updateScanPreferences(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'scan_devices' => ['required', Rule::in(['both', 'mobile', 'desktop'])],
        ]);

        $shop->update($data);

        return response()->json([
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

        if (! (new PlanPolicy($shop))->hasPaidPlan()) {
            return response()->json(['error' => 'Advanced delay is not available on the Free plan.'], 403);
        }

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

        if (! (new PlanPolicy($shop))->hasPaidPlan()) {
            return response()->json(['error' => 'Speed budget alerts are not available on the Free plan.'], 403);
        }

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
     * Manual-fix only, deliberately no "Auto fix on theme" button - unlike
     * every other fix in this app, badge *placement* is a design choice
     * (footer, about page, wherever), not a mechanical safe default the way
     * a <head> script tag is. There's no reliable universal anchor point in
     * an arbitrary theme's footer, and blindly regex-inserting visible
     * markup risks breaking a merchant's layout - so this hands back a
     * small, self-contained, dependency-free snippet for the merchant to
     * place themselves, same as every other "can't safely automate this"
     * case already handled this way.
     */
    public function trustBadgeCode(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        if (! (new PlanPolicy($shop))->hasPaidPlan()) {
            return response()->json(['error' => 'The trust badge is not available on the Free plan.'], 403);
        }

        $badge = <<<'HTML'
            <div style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border:1px solid #e1e1e1;border-radius:20px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-size:12px;color:#555;background:#fafafa;">
              <span style="font-size:14px;line-height:1;">⚡</span>
              <span>Optimized by SpeedPilot</span>
            </div>
            HTML;

        return response()->json(['snippet' => $badge]);
    }

    /**
     * "Instant navigation" - independent of Advanced Delay's tag, its own
     * global toggle (see ScriptImpactActionService::autoInstallPrefetchTag()).
     */
    public function installPrefetchTag(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        if (! (new PlanPolicy($shop))->hasPaidPlan()) {
            return response()->json(['error' => 'Instant navigation is not available on the Free plan.'], 403);
        }

        $actions = new ScriptImpactActionService(
            $themeAssets = new ThemeAssetService(new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token)),
            new ThemeAssetLocatorService($themeAssets),
            new AssetBackupService,
        );

        return response()->json($actions->autoInstallPrefetchTag($shop));
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

        if (! (new PlanPolicy($shop))->hasPaidPlan()) {
            return response()->json(['error' => 'Slack alerts are not available on the Free plan.'], 403);
        }

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

        if (! (new PlanPolicy($shop))->hasPaidPlan()) {
            return response()->json(['error' => 'Choosing a target theme is not available on the Free plan.'], 403);
        }

        $data = $request->validate([
            'theme_id' => 'required|string',
        ]);

        $themeAssets = new ThemeAssetService(new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token));
        $theme = collect($themeAssets->listThemes())->firstWhere('id', $data['theme_id']);

        if (! $theme) {
            return response()->json(['error' => 'Could not find that theme - it may have been deleted.'], 422);
        }

        // A real switch (not just re-confirming the same theme) makes every
        // existing scan stale for this new target - recorded so
        // AuditController::nextManualScanAt() can let the next manual scan
        // through immediately instead of enforcing a cooldown built for "the
        // merchant is re-scanning the same thing too often," which isn't
        // what's happening here.
        $themeChanged = $shop->target_theme_id !== $theme['id'];

        if ($theme['role'] === 'main') {
            $shop->update([
                'target_theme_id' => $theme['id'],
                'target_theme_mode' => 'live',
                'target_theme_changed_at' => $themeChanged ? now() : $shop->target_theme_changed_at,
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
                'target_theme_changed_at' => $themeChanged ? now() : $shop->target_theme_changed_at,
            ]);
        }

        if ($themeChanged) {
            $this->reinstallGlobalTagsOnNewTarget($shop);
        }

        return response()->json([
            'target_theme_id' => $shop->target_theme_id,
            'target_theme_mode' => $shop->target_theme_mode,
        ]);
    }

    /**
     * The self-heal half of the theme-switch story: interceptor_install and
     * prefetch_install are global, not-file-specific toggles (unlike
     * defer_css/minify_css, which depend on a theme's own actual CSS
     * content and can't be blindly carried over) - confirmed live this
     * session that switching target themes left the NEW theme without
     * either tag until the merchant remembered to re-click each toggle on
     * Settings. If this shop ever turned one on before (an 'applied' row on
     * ANY theme), re-run the same auto-install against the new target -
     * both methods are already idempotent (marker-check first) and
     * self-contained (create their own Optimization/backup row), so this
     * is just calling them again, not new logic. Failures here never block
     * the theme switch itself - re-running the toggle manually on Settings
     * remains the fallback.
     */
    private function reinstallGlobalTagsOnNewTarget(ShopInstallation $shop): void
    {
        $everApplied = fn (string $type) => $shop->optimizations()->where('type', $type)->where('status', 'applied')->exists();

        if (! $everApplied('interceptor_install') && ! $everApplied('prefetch_install')) {
            return;
        }

        try {
            $themeAssets = new ThemeAssetService(new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token));
            $actions = new ScriptImpactActionService($themeAssets, new ThemeAssetLocatorService($themeAssets), new AssetBackupService);

            if ($everApplied('interceptor_install')) {
                $actions->autoInstallInterceptorTag($shop);
            }

            if ($everApplied('prefetch_install')) {
                $actions->autoInstallPrefetchTag($shop);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
