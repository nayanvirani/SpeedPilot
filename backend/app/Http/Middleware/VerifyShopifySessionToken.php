<?php

namespace App\Http\Middleware;

use App\Jobs\RunAuditJob;
use App\Models\ShopInstallation;
use App\Services\Scanner\StorefrontAccessChecker;
use App\Services\Shopify\BillingService;
use App\Services\Shopify\ShopifyOAuthService;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Verifies the App Bridge session token the Remix frontend forwards as a
 * Bearer header, then resolves it to a shop_installations row on the
 * request so controllers never touch raw JWTs.
 *
 * Because shopify.app.toml has use_legacy_install_flow = false, Shopify
 * grants scopes and embeds the app itself ("managed installation") without
 * ever calling our classic /auth/callback - the first this app hears about
 * a shop is often a session token on a request exactly like this one. So on
 * first contact with an unknown (or previously uninstalled) shop, this
 * middleware performs Token Exchange itself to obtain an offline access
 * token and provisions the row right here, instead of rejecting the request.
 */
class VerifyShopifySessionToken
{
    public function __construct(
        private readonly ShopifyOAuthService $oauth,
        private readonly BillingService $billing,
        private readonly StorefrontAccessChecker $storefrontAccess,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['error' => 'Missing session token'], 401);
        }

        try {
            $payload = JWT::decode($token, new Key(config('shopify.api_secret'), 'HS256'));
        } catch (Throwable $e) {
            Log::warning('Shopify session token verification failed', ['message' => $e->getMessage()]);

            return response()->json(['error' => 'Invalid session token'], 401);
        }

        // dest looks like "https://{shop}.myshopify.com"
        $shopDomain = parse_url($payload->dest ?? '', PHP_URL_HOST);

        if (! $shopDomain) {
            return response()->json(['error' => 'Invalid session token'], 401);
        }

        $shop = ShopInstallation::where('shop_domain', $shopDomain)->first();

        if (! $shop || ! $shop->isActive() || $shop->needsFreshAccessToken()) {
            try {
                $shop = $this->provisionViaTokenExchange($shopDomain, $token);
            } catch (Throwable $e) {
                Log::error('Shopify token exchange failed', ['shop' => $shopDomain, 'message' => $e->getMessage()]);

                return response()->json(['error' => 'Shop not installed'], 401);
            }
        }

        $request->attributes->set('shop', $shop);

        return $next($request);
    }

    private function provisionViaTokenExchange(string $shopDomain, string $sessionToken): ShopInstallation
    {
        $tokenData = $this->oauth->exchangeSessionTokenForOfflineToken($shopDomain, $sessionToken);

        if (! isset($tokenData['access_token'])) {
            throw new \RuntimeException('Token exchange response had no access_token: '.json_encode($tokenData));
        }

        $shop = ShopInstallation::updateOrCreate(
            ['shop_domain' => $shopDomain],
            [
                'access_token' => $tokenData['access_token'],
                'access_token_expires_at' => isset($tokenData['expires_in'])
                    ? now()->addSeconds((int) $tokenData['expires_in'])
                    : null,
                'scope' => $tokenData['scope'] ?? null,
                'installed_at' => now(),
                'uninstalled_at' => null,
            ],
        );

        // The merchant already picked a plan as part of Shopify's managed
        // install flow - the app_subscriptions/update webhook may not have
        // landed yet, so seed it now rather than showing "no plan" briefly.
        // Non-fatal: a plan-sync hiccup shouldn't break auth itself, since
        // the webhook (or a manual admin resync) can still catch it later.
        try {
            $this->billing->syncActivePlanViaApi($shop);
        } catch (Throwable $e) {
            Log::warning('Initial plan sync failed during provisioning', [
                'shop' => $shopDomain,
                'message' => $e->getMessage(),
            ]);
        }

        // First contact ever (or a reinstall with no audit history) - queue
        // a scan right away instead of leaving the merchant looking at an
        // empty "No scans yet" dashboard until they think to click the
        // button themselves. Non-fatal: a queueing hiccup shouldn't break
        // auth, and the button is still right there either way. Skipped
        // entirely when the storefront is password-protected with no
        // password saved yet (impossible to be saved this early anyway) -
        // dispatching here would only ever produce a doomed "every page
        // failed" audit with no scores, which is exactly what a real
        // Shopify app review caught on first install.
        try {
            if ($shop->audits()->doesntExist() && ! $shop->hasAuditInProgress() && ! $this->storefrontAccess->blocksScan($shop)) {
                $audit = $shop->audits()->create(['status' => 'pending']);
                RunAuditJob::dispatch($audit->id);
            }
        } catch (Throwable $e) {
            Log::warning('Auto first-scan dispatch failed', [
                'shop' => $shopDomain,
                'message' => $e->getMessage(),
            ]);
        }

        return $shop->fresh();
    }
}
