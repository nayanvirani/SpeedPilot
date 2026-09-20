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

        // Cart and search are universal Shopify storefront routes - no API
        // lookup needed, unlike product/collection/blog which need a real
        // handle to point at.
        $pages[] = ['type' => 'cart', 'url' => "https://{$shop->shop_domain}/cart"];
        $pages[] = ['type' => 'search', 'url' => "https://{$shop->shop_domain}/search?q=shirt"];

        foreach ($this->fetchCandidates($shop) as $candidate) {
            if (count($pages) >= $limit) {
                break;
            }

            $pages[] = $candidate;
        }

        return array_slice($pages, 0, $limit);
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
                    products(first: 10, sortKey: UPDATED_AT, reverse: true) {
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

        // onlineStoreUrl is null for a product not published to the Online
        // Store sales channel - the single most-recently-updated product is
        // often not the one that's actually published (e.g. most recently
        // touched via a POS-only or draft edit), so check several rather
        // than giving up after the first. Collection has no onlineStoreUrl
        // field on the Admin API, so its storefront URL is built from
        // handle instead.
        $productUrl = null;
        foreach ($data['products']['edges'] ?? [] as $edge) {
            if ($edge['node']['onlineStoreUrl'] ?? null) {
                $productUrl = $edge['node']['onlineStoreUrl'];
                break;
            }
        }

        $collectionHandle = $data['collections']['edges'][0]['node']['handle'] ?? null;
        if ($productUrl) {
            $candidates[] = ['type' => 'product', 'url' => $productUrl];
        }

        if ($collectionHandle) {
            $candidates[] = ['type' => 'collection', 'url' => "https://{$shop->shop_domain}/collections/{$collectionHandle}"];
        }

        if ($blogArticle = $this->fetchLatestArticle($shop)) {
            $candidates[] = $blogArticle;
        }

        return $candidates;
    }

    /**
     * A separate call, not merged into fetchCandidates()'s combined query,
     * because it needs the read_content scope - a shop that installed
     * before that scope was added will get ACCESS_DENIED here specifically,
     * and ShopifyGraphQLClient throws on any GraphQL error even when other
     * fields in the same request would have succeeded. Isolating it keeps a
     * missing scope from also losing the product/collection results.
     *
     * @return ?array{type: string, url: string}
     */
    private function fetchLatestArticle(ShopInstallation $shop): ?array
    {
        try {
            $client = new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token);

            $data = $client->query(<<<'GRAPHQL'
                query latestArticle {
                    articles(first: 1, sortKey: UPDATED_AT, reverse: true, query: "published_status:published") {
                        edges { node { handle blog { handle } } }
                    }
                }
            GRAPHQL);
        } catch (Throwable $e) {
            Log::warning('Blog article discovery failed', ['shop' => $shop->shop_domain, 'message' => $e->getMessage()]);

            return null;
        }

        $article = $data['articles']['edges'][0]['node'] ?? null;

        if (! $article || ! $article['handle'] || ! ($article['blog']['handle'] ?? null)) {
            return null;
        }

        return ['type' => 'blog', 'url' => "https://{$shop->shop_domain}/blogs/{$article['blog']['handle']}/{$article['handle']}"];
    }
}
