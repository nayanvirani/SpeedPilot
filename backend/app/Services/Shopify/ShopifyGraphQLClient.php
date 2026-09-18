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
                'json' => ['query' => $query, 'variables' => $variables],
            ]);
        } catch (RequestException $e) {
            throw new RuntimeException(
                "Shopify GraphQL request failed for {$this->shopDomain}: ".$e->getMessage(),
                previous: $e,
            );
        }

        $body = json_decode((string) $response->getBody(), true);

        if (isset($body['errors'])) {
            throw new RuntimeException(
                "Shopify GraphQL returned errors: ".json_encode($body['errors']),
            );
        }

        return $body['data'] ?? [];
    }
}
