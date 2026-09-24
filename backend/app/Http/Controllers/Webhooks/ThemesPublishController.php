<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\RunAuditJob;
use App\Models\ShopInstallation;
use App\Services\Scanner\StorefrontAccessChecker;
use Illuminate\Http\Request;

/**
 * If the merchant switches themes, safe fixes written to the old theme's
 * files are gone. Detect that here and re-trigger an audit so fixes get
 * re-offered/re-applied against the newly published theme, per the spec's
 * theme-drift safeguard.
 */
class ThemesPublishController extends Controller
{
    public function __invoke(Request $request, StorefrontAccessChecker $storefrontAccess)
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

        return response('', 200);
    }
}
