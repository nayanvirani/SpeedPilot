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

    /**
     * @return array<int, string> every filename in the theme (paginated up
     *                             to 3 pages of 250 - generous for any theme)
     */
    public function listFilenames(string $themeId): array
    {
        $filenames = [];
        $after = null;

        for ($page = 0; $page < 3; $page++) {
            $data = $this->client->query(<<<'GRAPHQL'
                query themeFileNames($id: ID!, $after: String) {
                    theme(id: $id) {
                        files(first: 250, after: $after) {
                            nodes { filename }
                            pageInfo { hasNextPage endCursor }
                        }
                    }
                }
            GRAPHQL, ['id' => "gid://shopify/OnlineStoreTheme/{$themeId}", 'after' => $after]);

            $connection = $data['theme']['files'] ?? [];

            foreach ($connection['nodes'] ?? [] as $node) {
                $filenames[] = $node['filename'];
            }

            if (! ($connection['pageInfo']['hasNextPage'] ?? false)) {
                break;
            }

            $after = $connection['pageInfo']['endCursor'];
        }

        return $filenames;
    }

    /**
     * @param  array<int, string>  $assetKeys
     * @return array<string, ?string> filename => content (null if binary/missing)
     */
    public function readMany(string $themeId, array $assetKeys): array
    {
        if (empty($assetKeys)) {
            return [];
        }

        $data = $this->client->query(<<<'GRAPHQL'
            query themeFilesContent($id: ID!, $filenames: [String!]) {
                theme(id: $id) {
                    files(filenames: $filenames, first: 250) {
                        nodes {
                            filename
                            body { ... on OnlineStoreThemeFileBodyText { content } }
                        }
                    }
                }
            }
        GRAPHQL, ['id' => "gid://shopify/OnlineStoreTheme/{$themeId}", 'filenames' => $assetKeys]);

        $result = [];

        foreach ($data['theme']['files']['nodes'] ?? [] as $node) {
            $result[$node['filename']] = $node['body']['content'] ?? null;
        }

        return $result;
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

    /**
     * @return array<int, array{id: string, name: string, role: string}>
     */
    public function listThemes(): array
    {
        $data = $this->client->query(<<<'GRAPHQL'
            query allThemes {
                themes(first: 20) {
                    nodes { id name role }
                }
            }
        GRAPHQL);

        return array_map(
            fn (array $node) => [
                'id' => (string) filter_var($node['id'], FILTER_SANITIZE_NUMBER_INT),
                'name' => $node['name'],
                'role' => strtolower($node['role']),
            ],
            $data['themes']['nodes'] ?? [],
        );
    }
}
