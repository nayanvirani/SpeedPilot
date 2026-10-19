<?php

namespace App\Services\Shopify;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

/**
 * Thin wrapper around Shopify's Admin GraphQL API. One instance is scoped to a
 * single shop's access token - matches the "one install = one session" model.
 */
class ShopifyGraphQLClient
{
    private Client $http;

    public function __construct(
        private readonly string $shopDomain,
        private readonly string $accessToken,
    ) {
        $this->http = new Client([
            'base_uri' => "https://{$this->shopDomain}/admin/api/".config('shopify.api_version').'/',
            'timeout' => 30,
        ]);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function query(string $query, array $variables = []): array
    {
        try {
            $response = $this->http->post('graphql.json', [
                'headers' => [
                    'X-Shopify-Access-Token' => $this->accessToken,
                    'Content-Type' => 'application/json',
                ],
                // An empty PHP array encodes as JSON `[]`, but Shopify's
                // GraphQL endpoint requires `variables` to be an object -
                // `[]` gets rejected with "Invalid variables parameter."
                // Casting to stdClass when empty forces json_encode to
                // emit `{}` instead. Only affects queries with no $params
                // (e.g. activeSubscriptions) - anything with real variables
                // was never affected, which is why this stayed hidden.
                'json' => ['query' => $query, 'variables' => $variables ?: new \stdClass],
            ]);
        } catch (RequestException $e) {
            // Distinct from every other request failure: a 401 here means
            // the stored access token itself is expired/invalid, not a
            // scope/approval problem - see AccessTokenExpiredException for
            // why this can't just be retried in a background context.
            if ($e->getResponse()?->getStatusCode() === 401) {
                throw new AccessTokenExpiredException(
                    "Shopify rejected the access token for {$this->shopDomain}: ".$e->getMessage(),
                    previous: $e,
                );
            }

            throw new RuntimeException(
                "Shopify GraphQL request failed for {$this->shopDomain}: ".$e->getMessage(),
                previous: $e,
            );
        }

        $body = json_decode((string) $response->getBody(), true);

        if (isset($body['errors'])) {
            $codes = array_column(array_column($body['errors'], 'extensions'), 'code');

            if (in_array('ACCESS_DENIED', $codes, true)) {
                throw new ThemeWriteAccessDeniedException(
                    "Shopify denied this request - protected scope not yet exempted: ".json_encode($body['errors']),
                );
            }

            throw new RuntimeException(
                "Shopify GraphQL returned errors: ".json_encode($body['errors']),
            );
        }

        return $body['data'] ?? [];
    }

    /**
     * A handful of things (a theme asset's real public CDN URL, confirmed
     * live - GraphQL's OnlineStoreThemeFile type exposes no URL field at
     * all, only the legacy REST Asset endpoint's `public_url`) still have
     * no GraphQL equivalent. Shares this client's same base_uri/token
     * rather than standing up a second authenticated client for one field.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        try {
            $response = $this->http->get($path, [
                'headers' => ['X-Shopify-Access-Token' => $this->accessToken],
                'query' => $query,
            ]);
        } catch (RequestException $e) {
            if ($e->getResponse()?->getStatusCode() === 401) {
                throw new AccessTokenExpiredException(
                    "Shopify rejected the access token for {$this->shopDomain}: ".$e->getMessage(),
                    previous: $e,
                );
            }

            throw new RuntimeException(
                "Shopify REST request failed for {$this->shopDomain}: ".$e->getMessage(),
                previous: $e,
            );
        }

        return json_decode((string) $response->getBody(), true) ?? [];
    }
}
