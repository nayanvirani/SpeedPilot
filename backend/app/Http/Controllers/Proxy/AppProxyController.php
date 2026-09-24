<?php

namespace App\Http\Controllers\Proxy;

use App\Http\Controllers\Controller;
use App\Models\ShopInstallation;
use App\Services\Shopify\AppProxyVerifier;
use App\Services\Shopify\ScriptImpactActionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Everything Shopify forwards from https://{shop}/apps/speedpilot/* lands
 * here (see the [app_proxy] block in shopify.app.toml) - this exists
 * specifically so the Service Worker below can be registered same-origin
 * with the storefront, a hard browser requirement our own cross-origin
 * Railway domain can never satisfy on its own.
 */
class AppProxyController extends Controller
{
    public function serviceWorker(Request $request): Response
    {
        if (! AppProxyVerifier::isValid($request->query())) {
            return response('// invalid request', 401)
                ->header('Content-Type', 'application/javascript; charset=utf-8');
        }

        $shop = ShopInstallation::where('shop_domain', $request->query('shop'))->first();

        if (! $shop || ! $shop->isActive()) {
            return $this->emptyServiceWorker();
        }

        $patternsUrl = rtrim(config('app.url'), '/')
            .'/storefront/interceptor-patterns.json?t='.urlencode($shop->interceptorToken());

        return response(ScriptImpactActionService::serviceWorkerJs($patternsUrl), 200)
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            // A Service Worker script is exactly the kind of thing that
            // must never be aggressively cached - the browser already
            // re-checks it periodically on its own, but a short max-age
            // here avoids Shopify's proxy layer or an intermediate cache
            // holding onto a stale version while this is still being
            // iterated on.
            ->header('Cache-Control', 'public, max-age=60')
            // Lets the SW control the whole origin, not just /apps/speedpilot/
            // (its own default scope, derived from the request path).
            ->header('Service-Worker-Allowed', '/');
    }

    private function emptyServiceWorker(): Response
    {
        $body = <<<'JS'
            self.addEventListener('install', function () { self.skipWaiting(); });
            self.addEventListener('activate', function (event) { event.waitUntil(self.clients.claim()); });
            JS;

        return response($body, 200)
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=60')
            ->header('Service-Worker-Allowed', '/');
    }
}
