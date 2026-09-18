<?php

namespace App\Services\Shopify;

use App\Models\OptimizedTheme;
use App\Models\ShopInstallation;

/**
 * Maintains the single reusable "SpeedPilot Optimized" duplicate theme per shop
 * (never a fresh duplicate per run - Shopify caps stores at 20/100 themes and
 * duplication is an async job). Medium/high-risk fixes land here for preview
 * before the merchant explicitly publishes.
 */
class ThemeDuplicateService
{
    public function __construct(private readonly ShopifyGraphQLClient $client)
    {
    }

    public function getOrCreate(ShopInstallation $shop, string $sourceThemeId): OptimizedTheme
    {
        $existing = OptimizedTheme::where('shop_installation_id', $shop->id)
            ->where('source_theme_id', $sourceThemeId)
            ->first();

        if ($existing) {
            return $existing;
        }

        $duplicateId = $this->duplicateTheme($sourceThemeId);

        return OptimizedTheme::create([
            'shop_installation_id' => $shop->id,
            'duplicate_theme_id' => $duplicateId,
            'source_theme_id' => $sourceThemeId,
            'last_synced_at' => now(),
            'diverged' => false,
        ]);
    }

    private function duplicateTheme(string $sourceThemeId): string
    {
        $data = $this->client->query(<<<'GRAPHQL'
            mutation themeDuplicate($id: ID!, $name: String!) {
                themeDuplicate(id: $id, name: $name) {
                    theme { id }
                    userErrors { field message }
                }
            }
        GRAPHQL, [
            'id' => "gid://shopify/OnlineStoreTheme/{$sourceThemeId}",
            'name' => 'SpeedPilot Optimized',
        ]);

        $gid = $data['themeDuplicate']['theme']['id'] ?? '';

        return (string) filter_var($gid, FILTER_SANITIZE_NUMBER_INT);
    }

    /**
     * Before refreshing the duplicate with fresh optimizations, compare its
     * content/settings against the current live theme. If the merchant has kept
     * editing the live theme (new sections, settings) since the duplicate was
     * last synced, publishing the refreshed duplicate later would silently
     * revert that work - so we flag divergence instead of refreshing blindly.
     */
    public function checkDivergence(OptimizedTheme $optimizedTheme): bool
    {
        $liveChecksum = $this->themeSettingsChecksum($optimizedTheme->source_theme_id);
        $knownChecksum = $optimizedTheme->divergence_meta['source_checksum'] ?? null;

        $diverged = $knownChecksum !== null && $knownChecksum !== $liveChecksum;

        $optimizedTheme->update([
            'diverged' => $diverged,
            'divergence_meta' => ['source_checksum' => $liveChecksum, 'checked_at' => now()->toIso8601String()],
        ]);

        return $diverged;
    }

    private function themeSettingsChecksum(string $themeId): string
    {
        $data = $this->client->query(<<<'GRAPHQL'
            query themeSettings($id: ID!) {
                theme(id: $id) {
                    files(filenames: ["config/settings_data.json"], first: 1) {
                        nodes { body { ... on OnlineStoreThemeFileBodyText { content } } }
                    }
                }
            }
        GRAPHQL, ['id' => "gid://shopify/OnlineStoreTheme/{$themeId}"]);

        $content = $data['theme']['files']['nodes'][0]['body']['content'] ?? '';

        return hash('sha256', $content);
    }
}
