<?php

namespace App\Services\Shopify;

/**
 * Reads/writes individual theme files via the Asset API (GraphQL themeFilesUpsert /
 * REST Asset endpoint under the hood). Safe-tier fixes are applied live, in-place,
 * through this service - never Script Tags, per the App Store's theme-touching rules.
 */
class ThemeAssetService
{
    public function __construct(private readonly ShopifyGraphQLClient $client)
    {
    }

    public function read(string $themeId, string $assetKey): ?string
    {
        $data = $this->client->query(<<<'GRAPHQL'
            query themeFile($id: ID!, $filename: String!) {
                theme(id: $id) {
                    files(filenames: [$filename], first: 1) {
                        nodes {
                            body {
                                ... on OnlineStoreThemeFileBodyText { content }
                            }
                        }
                    }
                }
            }
        GRAPHQL, [
            'id' => "gid://shopify/OnlineStoreTheme/{$themeId}",
            'filename' => $assetKey,
        ]);

        return $data['theme']['files']['nodes'][0]['body']['content'] ?? null;
    }

    public function write(string $themeId, string $assetKey, string $content): void
    {
        $this->client->query(<<<'GRAPHQL'
            mutation themeFilesUpsert($themeId: ID!, $files: [OnlineStoreThemeFilesUpsertFileInput!]!) {
                themeFilesUpsert(themeId: $themeId, files: $files) {
                    userErrors { field message }
                }
            }
        GRAPHQL, [
            'themeId' => "gid://shopify/OnlineStoreTheme/{$themeId}",
            'files' => [[
                'filename' => $assetKey,
                'body' => ['type' => 'TEXT', 'value' => $content],
            ]],
        ]);
    }

    public function activeThemeId(): ?string
    {
        $data = $this->client->query(<<<'GRAPHQL'
            query activeTheme {
                themes(first: 1, roles: [MAIN]) {
                    nodes { id }
                }
            }
        GRAPHQL);

        $gid = $data['themes']['nodes'][0]['id'] ?? null;

        return $gid ? (string) filter_var($gid, FILTER_SANITIZE_NUMBER_INT) : null;
    }
}
