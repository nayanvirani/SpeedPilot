<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ApplySafeFixesJob;
use App\Models\AppImpact;
use App\Models\AuditIssue;
use App\Models\Optimization;
use App\Models\ShopInstallation;
use App\Services\PlanPolicy;
use App\Services\Shopify\AssetBackupService;
use App\Services\Shopify\MediumFixService;
use App\Services\Shopify\ScriptImpactActionService;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\ThemeAssetLocatorService;
use App\Services\Shopify\ThemeAssetService;
use App\Services\Shopify\ThemeWriteAccessDeniedException;
use Illuminate\Http\Request;
use RuntimeException;

class OptimizationController extends Controller
{
    public function index(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $optimizations = $shop->optimizations()->with(['backups', 'auditIssue', 'appImpact'])->latest()->get()
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

    /**
     * The real fix for a "Different theme now targeted" row - reapply()
     * above restores the SAME backup to the SAME stale theme_id it was
     * always going to (AssetBackupService::reapply() never looks at the
     * shop's current target), so it can't actually get a fix back onto a
     * theme the merchant has since switched to. This instead re-runs the
     * same underlying action the original "Auto fix on theme" button used
     * - every one of those already reads $shop->target_theme_id fresh at
     * call time, so re-triggering it naturally lands on whichever theme is
     * current now, creating a new Optimization row rather than editing the
     * old (now orphaned) one.
     */
    public function reapplyToCurrentTheme(Request $request, int $id)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');
        $policy = new PlanPolicy($shop);

        if (! $policy->hasPaidPlan()) {
            return response()->json(['error' => 'Re-applying a fix is not available on the Free plan.'], 403);
        }

        if (! $shop->target_theme_id) {
            return response()->json(['error' => 'Choose a target theme in Settings first.'], 422);
        }

        $optimization = Optimization::where('shop_installation_id', $shop->id)->findOrFail($id);

        if ($optimization->theme_id === $shop->target_theme_id) {
            return response()->json(['error' => 'This fix already targets your current theme.'], 422);
        }

        try {
            $newOptimization = $this->rerunOriginalAction($optimization, $shop, $policy);
        } catch (ThemeWriteAccessDeniedException) {
            return response()->json([
                'error' => "Couldn't apply this automatically right now - Shopify's theme-write approval may still be pending.",
            ], 503);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            // A genuinely unexpected failure (not one of this method's own
            // RuntimeExceptions) still deserves a real message, not a raw
            // 500 the frontend can't parse into anything better than its
            // own generic fallback - confirmed live: a DI construction bug
            // here surfaced to the merchant as just "Could not re-apply
            // this" with no indication anything was actually broken.
            report($e);

            return response()->json(['error' => "Something went wrong re-applying this - try again, or contact support if it keeps happening."], 500);
        }

        if (! $newOptimization) {
            return response()->json([
                'error' => "Couldn't re-apply this fix type automatically - open the original issue or app row and use its own Auto fix button instead.",
            ], 422);
        }

        return response()->json(['optimization' => $newOptimization]);
    }

    private function rerunOriginalAction(Optimization $optimization, ShopInstallation $shop, PlanPolicy $policy): ?Optimization
    {
        if ($optimization->audit_issue_id !== null) {
            $issue = AuditIssue::find($optimization->audit_issue_id);

            if (! $issue) {
                throw new RuntimeException("Couldn't find the original issue this fix came from - run a fresh scan first.");
            }

            if ($issue->risk_tier === 'safe') {
                if (! $policy->canAutoFix()) {
                    throw new RuntimeException('Automatic fixes are not available on your plan.');
                }

                ApplySafeFixesJob::dispatchSync($shop->id, $issue->audit_id, onlyIssueId: $issue->id);

                $created = $shop->optimizations()
                    ->where('audit_issue_id', $issue->id)
                    ->where('theme_id', $shop->target_theme_id)
                    ->where('status', 'applied')
                    ->latest('applied_at')
                    ->first();

                // The sweep ran but changed nothing - almost always because
                // whatever it was targeting (a specific image, a specific
                // section) doesn't exist in the same form on the current
                // theme, or the current theme already has the fix some
                // other way. Distinct from "this fix type isn't handled
                // here at all" (the generic message below), which a
                // merchant would otherwise wrongly read this as.
                if (! $created) {
                    throw new RuntimeException(
                        "Nothing to change on your current theme - whatever this fix targeted may not exist "
                        .'there, or may already be fine. Check the original issue on a fresh scan instead.'
                    );
                }

                return $created;
            }

            if ($issue->risk_tier === 'medium') {
                if (! $policy->canApplyMediumRiskFixes()) {
                    throw new RuntimeException('Medium-risk fixes are not available on your plan.');
                }

                return $this->mediumFixService($shop)->apply($issue, $shop);
            }

            return null;
        }

        if ($optimization->app_impact_id !== null) {
            $impact = AppImpact::find($optimization->app_impact_id);

            if (! $impact) {
                throw new RuntimeException("Couldn't find this app in your latest scan - run a fresh scan first.");
            }

            $actions = $this->scriptImpactActionService($shop);

            $result = match ($optimization->type) {
                'disabled' => $actions->disable($shop, $impact),
                'delayed' => $actions->delay($shop, $impact),
                'content_stop' => $actions->stopContentMatch($shop, $impact),
                default => ['applied' => false, 'message' => null],
            };

            if (! ($result['applied'] ?? false)) {
                throw new RuntimeException($result['message'] ?? "Couldn't apply this to your current theme.");
            }

            return $shop->optimizations()
                ->where('app_impact_id', $impact->id)
                ->where('theme_id', $shop->target_theme_id)
                ->where('status', 'applied')
                ->latest('applied_at')
                ->first();
        }

        if ($optimization->type === 'interceptor_install' || $optimization->type === 'prefetch_install') {
            $actions = $this->scriptImpactActionService($shop);
            $result = $optimization->type === 'interceptor_install'
                ? $actions->autoInstallInterceptorTag($shop)
                : $actions->autoInstallPrefetchTag($shop);

            if (! ($result['applied'] ?? false)) {
                throw new RuntimeException($result['message'] ?? "Couldn't apply this to your current theme.");
            }

            // Both methods create a fresh Optimization row via
            // $shop->optimizations()->create() when they actually write
            // (not $optimization itself, which is the stale, orphaned row
            // this whole endpoint exists to move on from).
            return $shop->optimizations()
                ->where('type', $optimization->type)
                ->where('theme_id', $shop->target_theme_id)
                ->where('status', 'applied')
                ->latest('applied_at')
                ->first();
        }

        return null;
    }

    private function mediumFixService(ShopInstallation $shop): MediumFixService
    {
        $themeAssets = new ThemeAssetService(new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token));

        return new MediumFixService($themeAssets, new ThemeAssetLocatorService($themeAssets), new AssetBackupService);
    }

    private function scriptImpactActionService(ShopInstallation $shop): ScriptImpactActionService
    {
        $themeAssets = new ThemeAssetService(new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token));

        return new ScriptImpactActionService($themeAssets, new ThemeAssetLocatorService($themeAssets), new AssetBackupService);
    }
}
