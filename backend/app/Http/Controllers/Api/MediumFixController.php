<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\ShopInstallation;
use App\Services\PlanPolicy;
use App\Services\Shopify\AssetBackupService;
use App\Services\Shopify\MediumFixService;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\ThemeAssetLocatorService;
use App\Services\Shopify\ThemeAssetService;
use App\Services\Shopify\ThemeWriteAccessDeniedException;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Spec 4.12's medium risk tier: unlike ApplySafeFixesJob (auto-applies on
 * every scan), nothing here runs unless the merchant clicks a button - first
 * preview (read-only, no write), then a separate explicit apply.
 */
class MediumFixController extends Controller
{
    public function preview(Request $request, int $issueId)
    {
        // Showing the exact before/after code is "a solution" the same way
        // Manual fix is - gated the same as apply(), not left open as a
        // free-tier teaser (see PlanPolicy::hasPaidPlan()).
        [$shop, $issue, $error] = $this->resolve($request, $issueId, requirePlan: true);

        if ($error) {
            return $error;
        }

        try {
            return response()->json(['preview' => $this->service($shop)->preview($issue, $shop)]);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function apply(Request $request, int $issueId)
    {
        [$shop, $issue, $error] = $this->resolve($request, $issueId, requirePlan: true);

        if ($error) {
            return $error;
        }

        try {
            $optimization = $this->service($shop)->apply($issue, $shop);

            $shop->update(['theme_write_blocked_at' => null]);

            return response()->json(['optimization' => $optimization]);
        } catch (ThemeWriteAccessDeniedException) {
            // An app-wide platform restriction, not a per-store problem -
            // logged for internal tracking (theme_write_blocked_at) but
            // never explained to the merchant as "Shopify hasn't approved
            // us"; they're just pointed at the manual alternative.
            $shop->update(['theme_write_blocked_at' => now()]);

            return response()->json([
                'error' => "Couldn't apply this automatically right now - use Manual fix above to apply it yourself.",
            ], 503);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * One click to defer every render-blocking stylesheet this scan found,
     * instead of clicking through "Preview -> Confirm" per issue - see
     * MediumFixService::applyAllDeferCss() for why that mattered live (a
     * theme with 2+ flagged stylesheets only ever got the first one fixed
     * in practice).
     */
    public function applyAllDeferCss(Request $request, int $auditId)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        if (! (new PlanPolicy($shop))->canApplyMediumRiskFixes()) {
            return response()->json(['error' => 'Medium-risk fixes are not available on your plan.'], 403);
        }

        $audit = Audit::where('shop_installation_id', $shop->id)->findOrFail($auditId);
        $issues = $audit->issues()->where('risk_tier', 'medium')->get();

        try {
            $optimizations = $this->service($shop)->applyAllDeferCss($issues, $shop);

            $shop->update(['theme_write_blocked_at' => null]);

            return response()->json(['optimizations' => $optimizations]);
        } catch (ThemeWriteAccessDeniedException) {
            $shop->update(['theme_write_blocked_at' => now()]);

            return response()->json([
                'error' => "Couldn't apply this automatically right now - use Manual fix on each issue instead.",
            ], 503);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * @return array{0: ?ShopInstallation, 1: ?AuditIssue, 2: ?\Illuminate\Http\JsonResponse}
     */
    private function resolve(Request $request, int $issueId, bool $requirePlan): array
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        if ($requirePlan && ! (new PlanPolicy($shop))->canApplyMediumRiskFixes()) {
            return [null, null, response()->json(['error' => 'Medium-risk fixes are not available on your plan.'], 403)];
        }

        $issue = AuditIssue::whereHas('audit', fn ($q) => $q->where('shop_installation_id', $shop->id))
            ->findOrFail($issueId);

        return [$shop, $issue, null];
    }

    private function service(ShopInstallation $shop): MediumFixService
    {
        $client = new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token);
        $themeAssets = new ThemeAssetService($client);

        return new MediumFixService($themeAssets, new ThemeAssetLocatorService($themeAssets), new AssetBackupService);
    }
}
