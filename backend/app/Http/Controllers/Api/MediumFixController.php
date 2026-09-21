<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        [$shop, $issue, $error] = $this->resolve($request, $issueId, requirePlan: false);

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
            $shop->update(['theme_write_blocked_at' => now()]);

            return response()->json([
                'error' => "Shopify hasn't approved this app's theme-editing access yet - this is a one-time ".
                    'approval on Shopify\'s side. The preview above is accurate; applying it will work once that clears.',
                'exemption_form_url' => ThemeWriteAccessDeniedException::EXEMPTION_FORM_URL,
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
