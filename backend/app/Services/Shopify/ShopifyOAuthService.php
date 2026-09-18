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
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }
}
