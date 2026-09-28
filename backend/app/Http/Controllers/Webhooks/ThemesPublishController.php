<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\RunAuditJob;
use App\Models\ShopInstallation;
use App\Services\Scanner\StorefrontAccessChecker;
use App\Services\Shopify\OptimizationDriftChecker;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\ThemeAssetService;
use App\Services\SlackNotifier;
use Illuminate\Http\Request;

/**
 * If the merchant switches themes, safe fixes written to the old theme's
 * files are gone. Detect that here and re-trigger an audit so fixes get
 * re-offered/re-applied against the newly published theme, per the spec's
 * theme-drift safeguard. Also runs OptimizationDriftChecker for fixes still
 * targeting the *current* theme_id, in case this publish was an edit to the
 * same theme that happened to overwrite one of them.
 */
class ThemesPublishController extends Controller
{
    public function __invoke(Request $request, StorefrontAccessChecker $storefrontAccess, SlackNotifier $slack)
    {
        $shopDomain = $request->header('X-Shopify-Shop-Domain');
        $shop = ShopInstallation::where('shop_domain', $shopDomain)->first();

        if ($shop && $shop->isActive() && ! $shop->hasAuditInProgress() && ! $storefrontAccess->blocksScan($shop)) {
            // url stays null so RunAuditJob re-audits every page the plan
            // covers, not just the homepage - a theme switch can move fixes
            // out from under any of them, not only the home template.
            $audit = $shop->audits()->create(['status' => 'pending']);

            RunAuditJob::dispatch($audit->id);
        }

        if ($shop && $shop->isActive() && $shop->target_theme_id) {
            $checker = new OptimizationDriftChecker(
                new ThemeAssetService(new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token))
            );
            $flagged = $checker->check($shop);

            if ($flagged > 0) {
                $slack->send($shop, sprintf(
                    ':warning: SpeedPilot: %d applied fix%s on %s look%s reverted since your theme was '
                        .'updated - review and re-apply on the Optimizations page.',
                    $flagged,
                    $flagged === 1 ? '' : 'es',
                    $shop->shop_domain,
                    $flagged === 1 ? 's' : '',
                ));
            }
        }

        return response('', 200);
    }
}
