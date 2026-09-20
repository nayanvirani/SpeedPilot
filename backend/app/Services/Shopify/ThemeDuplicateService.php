<?php

namespace App\Services\Shopify;

use App\Models\OptimizedTheme;
use App\Models\ShopInstallation;

/**
 * Tracks a preview theme the merchant picked as SpeedPilot's write target -
 * one they duplicated themselves in Shopify admin (Shopify's own theme
 * library UI already does this, no API call or write_themes-gated mutation
 * needed on our side). This is purely bookkeeping for the divergence check
 * below: which live theme a given preview theme was picked alongside, so a
 * later refresh can tell whether the merchant kept editing the live one
 * since.
 */
class ThemeDuplicateService
{
    public function __construct(private readonly ShopifyGraphQLClient $client)
    {
    }

    public function trackPreviewTheme(ShopInstallation $shop, string $sourceThemeId, string $previewThemeId): OptimizedTheme
    {
        return OptimizedTheme::firstOrCreate(
            ['shop_installation_id' => $shop->id, 'duplicate_theme_id' => $previewThemeId],
            ['source_theme_id' => $sourceThemeId, 'last_synced_at' => now(), 'diverged' => false],
        );
    }

    /**
     * Before refreshing the duplicate with fresh optimizations, compare its
     * content/settings against the current live theme. If the merchant has kept
     * editing the live theme (new sections, settings) since the duplicate was
     * last synced, publishing the refreshed duplicate later would silently
     * revert that work - so we flag divergence instead of refreshing blindly.
     */
    public function checkDivergence(OptimizedTheme $optimizedTheme, bool $persist = true): bool
    {
        $liveChecksum = $this->themeSettingsChecksum($optimizedTheme->source_theme_id);
        $knownChecksum = $optimizedTheme->divergence_meta['source_checksum'] ?? null;

        $diverged = $knownChecksum !== null && $knownChecksum !== $liveChecksum;

        // Only re-baseline the "known good" checksum when this check is
        // gating an actual write (about to refresh the duplicate with new
        // fixes) - a read-only display check (e.g. a settings-page banner)
        // must never move the baseline, or drift would never accumulate
        // long enough to ever be reported.
        if ($persist) {
            $optimizedTheme->update([
                'diverged' => $diverged,
                'divergence_meta' => ['source_checksum' => $liveChecksum, 'checked_at' => now()->toIso8601String()],
            ]);
        }

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
