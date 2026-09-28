<?php

namespace App\Services\Shopify;

use App\Models\ShopInstallation;

/**
 * Builds the content for /llms.txt, the emerging plain-text convention AI
 * agents/LLM crawlers look for to understand a site - Shopify serves this
 * natively from `templates/llms.txt.liquid` (confirmed against Shopify's own
 * theme architecture docs, alongside robots.txt.liquid/agents.md.liquid),
 * so writing it is a plain theme-file write through ThemeAssetService, the
 * same mechanism every other fix in this app already uses - nothing new to
 * host or route.
 */
class LlmsTxtGenerator
{
    public function __construct(private readonly ShopifyGraphQLClient $client)
    {
    }

    public function generate(ShopInstallation $shop): string
    {
        $data = $this->client->query(<<<'GRAPHQL'
            query llmsTxtSource {
                shop { name }
                collections(first: 10, sortKey: UPDATED_AT, reverse: true) {
                    edges { node { title handle } }
                }
            }
        GRAPHQL);

        $shopName = $data['shop']['name'] ?? $shop->shop_domain;
        $domain = $shop->shop_domain;
        $storeUrl = "https://{$domain}";

        $lines = [
            "# {$shopName}",
            '',
            "> {$shopName} is an online store built on Shopify.",
            '',
            '## Store',
            "- Homepage: {$storeUrl}",
            "- Sitemap: {$storeUrl}/sitemap.xml",
        ];

        $collections = collect($data['collections']['edges'] ?? [])->pluck('node');

        if ($collections->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '## Collections';

            foreach ($collections as $collection) {
                $lines[] = "- {$collection['title']}: {$storeUrl}/collections/{$collection['handle']}";
            }
        }

        return implode("\n", $lines)."\n";
    }
}
