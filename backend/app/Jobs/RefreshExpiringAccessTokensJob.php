<?php

namespace App\Jobs;

use App\Models\ShopInstallation;
use App\Services\Shopify\ShopifyOAuthService;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Proactively renews one shop's expiring offline access token (1-hour
 * lifetime) using its stored refresh_token (90-day lifetime) - no merchant
 * session involved, per Shopify's "Refresh an expiring offline token" flow
 * (shopify.dev/docs/apps/build/authentication-authorization/implement-token-
 * exchange#refresh-an-expiring-offline-token).
 *
 * Before this job existed, the only thing that ever refreshed a shop's
 * offline token was VerifyShopifySessionToken re-running token exchange on a
 * live embedded-app request - so a shop nobody opened the app for in over an
 * hour had no valid token for any background job (scheduled scans, auto-fix,
 * webhook processing) until a merchant next visited. Dispatched per-shop
 * from routes/console.php's schedule for every shop whose token is close to
 * expiring, independent of app usage.
 */
class RefreshExpiringAccessTokensJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $shopInstallationId,
    ) {
    }

    public function handle(ShopifyOAuthService $oauth): void
    {
        $shop = ShopInstallation::find($this->shopInstallationId);

        if (! $shop || ! $shop->refresh_token) {
            return;
        }

        // The refresh_token itself expires after 90 days of never being
        // used (e.g. a shop stuck failing, or an old row from before this
        // job existed) - calling refresh with a dead refresh_token would
        // just fail the same way every tick. Wait for a merchant to reopen
        // the app instead, which re-provisions a fresh pair via token
        // exchange.
        if ($shop->refresh_token_expires_at && $shop->refresh_token_expires_at->isPast()) {
            return;
        }

        try {
            $tokenData = $oauth->refreshOfflineToken($shop->shop_domain, $shop->refresh_token);
        } catch (RequestException $e) {
            $status = $e->getResponse()?->getStatusCode();

            if ($status === 401) {
                // Per Shopify's own guidance, a 401 here is final, not
                // transient - the refresh_token is dead (revoked, already
                // rotated by a call this app somehow lost the response to,
                // or the app's uninstalled on Shopify's side). Stop
                // retrying and fall back to needs_reauth_at, the same
                // "wait for the merchant to reopen the app" signal
                // AccessTokenExpiredException already uses elsewhere.
                $shop->update(['refresh_token' => null, 'needs_reauth_at' => now()]);
                Log::warning('Offline token refresh_token is dead - waiting for merchant to reopen the app', [
                    'shop' => $shop->shop_domain,
                ]);

                return;
            }

            // Network error, timeout, 5xx, 429 - safe to retry with the same
            // refresh_token, so just leave everything as-is for the next
            // scheduled tick rather than burning the refresh_token on a
            // response we can't trust.
            Log::warning('Offline token refresh failed transiently, retrying next tick', [
                'shop' => $shop->shop_domain,
                'status' => $status,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        if (! isset($tokenData['access_token'])) {
            Log::warning('Offline token refresh response had no access_token', ['shop' => $shop->shop_domain]);

            return;
        }

        $shop->update([
            'access_token' => $tokenData['access_token'],
            'access_token_expires_at' => isset($tokenData['expires_in'])
                ? now()->addSeconds((int) $tokenData['expires_in'])
                : null,
            // Every refresh issues a brand new refresh_token - the one just
            // used is spent, and Shopify rejects it if reused next time.
            'refresh_token' => $tokenData['refresh_token'] ?? null,
            'refresh_token_expires_at' => isset($tokenData['refresh_token_expires_in'])
                ? now()->addSeconds((int) $tokenData['refresh_token_expires_in'])
                : null,
            'needs_reauth_at' => null,
        ]);
    }
}
