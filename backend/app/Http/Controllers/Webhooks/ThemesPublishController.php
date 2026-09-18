<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\RunAuditJob;
use App\Models\ShopInstallation;
use App\Services\Shopify\WebhookVerifier;
use Illuminate\Http\Request;

/**
 * If the merchant switches themes, safe fixes written to the old theme's
 * files are gone. Detect that here and re-trigger an audit so fixes get
 * re-offered/re-applied against the newly published theme, per the spec's
 * theme-drift safeguard.
 */
class ThemesPublishController extends Controller
{
    public function __invoke(Request $request)
    {
        if (! WebhookVerifier::isValid($request->getContent(), $request->header('X-Shopify-Hmac-Sha256', ''))) {
            return response('Invalid signature', 401);
        }

        $shopDomain = $request->header('X-Shopify-Shop-Domain');
        $shop = ShopInstallation::where('shop_domain', $shopDomain)->first();

        if ($shop && $shop->isActive()) {
            RunAuditJob::dispatch($shop->id, "https://{$shop->shop_domain}");
        }

        return response('', 200);
    }
}
