<?php

use App\Http\Middleware\EnsureIsSuperAdmin;
use App\Http\Middleware\VerifyShopifySessionToken;
use App\Http\Middleware\VerifyShopifyWebhook;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Railway proxies every request through its edge - trust it for the
        // forwarded proto/host/ip headers so $request->isSecure() and
        // $request->ip() are correct (Railway's internal network is the only
        // thing that can reach this container, so trusting '*' here is safe).
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR |
            Request::HEADER_X_FORWARDED_HOST |
            Request::HEADER_X_FORWARDED_PORT |
            Request::HEADER_X_FORWARDED_PROTO);

        $middleware->alias([
            'shopify.session' => VerifyShopifySessionToken::class,
            'super_admin' => EnsureIsSuperAdmin::class,
            'shopify.webhook' => VerifyShopifyWebhook::class,
        ]);

        // Webhook routes live in routes/web.php (for the admin panel's
        // session/CSRF needs elsewhere in that file) and so inherited
        // Laravel's default CSRF verification - Shopify's webhook POSTs
        // carry no session/CSRF token at all, so every single delivery was
        // silently rejected with 419 before ever reaching a controller.
        // HMAC verification (shopify.webhook middleware) is this endpoint's
        // real authenticity check, not CSRF.
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
