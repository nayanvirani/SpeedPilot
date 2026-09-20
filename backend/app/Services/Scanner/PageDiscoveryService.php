<?php

namespace App\Services\Scanner;

use App\Models\ShopInstallation;
use App\Services\Shopify\ShopifyGraphQLClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Picks which storefront pages a full-store scan covers. A homepage-only
 * score misses slowdowns specific to product/collection templates, so
 * plans with pages_per_scan > 1 (see PlanPolicy) get those added too.
 */
class PageDiscoveryService
{
    /**
     * @return array<int, array{type: string, url: string}>
     */
    public function discover(ShopInstallation $shop, int $limit): array
    {
        $pages = [['type' => 'home', 'url' => "https://{$shop->shop_domain}"]];

        if ($limit <= 1) {
            return $pages;
        }

        foreach ($this->fetchCandidates($shop) as $candidate) {
            if (count($pages) >= $limit) {
                break;
            }

            $pages[] = $candidate;
        }

        return $pages;
    }

    /**
     * @return array<int, array{type: string, url: string}>
     */
    private function fetchCandidates(ShopInstallation $shop): array
    {
        try {
            $client = new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token);

            $data = $client->query(<<<'GRAPHQL'
                query topStorefrontPages {
                    products(first: 1, sortKey: UPDATED_AT, reverse: true) {
                        edges { node { onlineStoreUrl } }
                    }
                    collections(first: 1, sortKey: UPDATED_AT, reverse: true) {
                        edges { node { handle } }
                    }
                }
            GRAPHQL);
        } catch (Throwable $e) {
            // A discovery failure shouldn't block the homepage scan that's
            // already guaranteed above - just fall back to fewer pages.
            Log::warning('Page discovery failed', ['shop' => $shop->shop_domain, 'message' => $e->getMessage()]);

            return [];
        }

        $candidates = [];

        $productUrl = $data['products']['edges'][0]['node']['onlineStoreUrl'] ?? null;
        $collectionHandle = $data['collections']['edges'][0]['node']['handle'] ?? null;

        // onlineStoreUrl is null for a product not published to the Online
        // Store sales channel - skip it rather than scan null. Collection
        // has no onlineStoreUrl field on the Admin API, so its storefront
        // URL is built from handle instead.
        if ($productUrl) {
            $candidates[] = ['type' => 'product', 'url' => $productUrl];
        }

        if ($collectionHandle) {
            $candidates[] = ['type' => 'collection', 'url' => "https://{$shop->shop_domain}/collections/{$collectionHandle}"];
        }

        return $candidates;
    }
}
