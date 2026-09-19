<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Serves either the React + Polaris embedded app shell (when Shopify is
 * loading this URL inside the Admin iframe, indicated by the `shop`/`host`
 * query params it always attaches) or the public marketing/pricing page
 * (when the URL is visited directly, e.g. someone opening the Railway
 * domain in a browser).
 *
 * This shell renders unconditionally - it does not check whether the shop
 * has a stored access token. Shopify's "managed installation"
 * (use_legacy_install_flow = false in shopify.app.toml) grants scopes and
 * embeds the app itself before this URL is ever loaded, so by the time we
 * get here the shop is always allowed to be here; VerifyShopifySessionToken
 * provisions the shop record (via Token Exchange) on the embedded app's
 * first authenticated API call if one doesn't exist yet.
 */
class EmbeddedAppController extends Controller
{
    public function __invoke(Request $request): View
    {
        if (! $request->query('shop') && ! $request->query('host')) {
            return view('welcome');
        }

        return view('embedded', [
            'apiKey' => config('shopify.api_key'),
            'host' => (string) $request->query('host', ''),
        ]);
    }
}
