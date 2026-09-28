<?php

namespace App\Services\Shopify;

use GuzzleHttp\Client;
use InvalidArgumentException;

class ShopifyOAuthService
{
    public function __construct(private readonly Client $http = new Client)
    {
    }

    public function isValidShopDomain(string $shop): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/', $shop);
    }

    public function buildInstallUrl(string $shop, string $state): string
    {
        if (! $this->isValidShopDomain($shop)) {
            throw new InvalidArgumentException("Invalid shop domain: {$shop}");
        }

        $params = [
            'client_id' => config('shopify.api_key'),
            'scope' => config('shopify.scopes'),
            'redirect_uri' => rtrim(config('shopify.app_url'), '/').'/auth/callback',
            'state' => $state,
        ];

        return "https://{$shop}/admin/oauth/authorize?".http_build_query($params);
    }

    /**
     * Verifies the HMAC signature Shopify attaches to OAuth callback query params.
     * This uses hex-encoded HMAC over sorted "key=value" pairs - distinct from the
     * base64 HMAC Shopify uses to sign webhook request bodies.
     *
     * @param  array<string, string>  $query
     */
    public function verifyHmac(array $query): bool
    {
        $hmac = $query['hmac'] ?? '';
        unset($query['hmac'], $query['signature']);

        ksort($query);
        $computed = hash_hmac(
            'sha256',
            urldecode(http_build_query($query)),
            config('shopify.api_secret'),
        );

        return hash_equals($computed, $hmac);
    }

    public function exchangeCodeForToken(string $shop, string $code): array
    {
        $response = $this->http->post("https://{$shop}/admin/oauth/access_token", [
            'json' => [
                'client_id' => config('shopify.api_key'),
                'client_secret' => config('shopify.api_secret'),
                'code' => $code,
                // Same requirement as exchangeSessionTokenForOfflineToken -
                // without this, Shopify issues a deprecated non-expiring
                // token instead of rejecting the request outright, which is
                // what let this fallback route slip a permanent token into
                // shop_installations undetected until Partner Dashboard's
                // API health flagged it.
                'expiring' => 1,
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * With shopify.app.toml's use_legacy_install_flow = false, Shopify grants
     * scopes and embeds the app itself ("managed installation") without ever
     * calling /auth/callback - the classic OAuth flow above is a fallback,
     * not the primary path. Token Exchange is how the app actually obtains
     * an offline access token: it swaps the App Bridge session token
     * (already proven valid - this shop has it because Shopify itself
     * handed it a token for this app) for a real API access token, on the
     * first authenticated request from an unknown shop.
     */
    public function exchangeSessionTokenForOfflineToken(string $shop, string $sessionToken): array
    {
        $response = $this->http->post("https://{$shop}/admin/oauth/access_token", [
            'json' => [
                'client_id' => config('shopify.api_key'),
                'client_secret' => config('shopify.api_secret'),
                'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
                'subject_token' => $sessionToken,
                'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
                'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
                // Without this, Shopify issues a non-expiring offline token,
                // which the Admin API now rejects outright ("Non-expiring
                // access tokens are no longer accepted") - this is required,
                // not optional, on current API versions.
                'expiring' => 1,
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * Renews an expiring offline access token using the refresh_token
     * issued alongside it - no merchant session or ID token involved, so
     * this runs from a scheduled job for every installed shop regardless of
     * whether anyone has opened the embedded app recently. Per Shopify's
     * docs ("Refresh an expiring offline token"): every refresh returns a
     * new access_token AND a new refresh_token - the old refresh_token is
     * spent and must be replaced with the new one, not reused.
     *
     * Throws on any HTTP error - RefreshExpiringAccessTokensJob distinguishes
     * a final 401 (dead refresh_token, needs a merchant to reopen the app)
     * from a transient failure (network/5xx/429, safe to retry next tick)
     * by inspecting the exception there.
     */
    public function refreshOfflineToken(string $shop, string $refreshToken): array
    {
        $response = $this->http->post("https://{$shop}/admin/oauth/access_token", [
            'json' => [
                'client_id' => config('shopify.api_key'),
                'client_secret' => config('shopify.api_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }
}
