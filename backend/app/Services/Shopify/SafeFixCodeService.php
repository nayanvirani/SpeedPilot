<?php

namespace App\Services\Shopify;

use App\Models\AuditIssue;
use RuntimeException;

/**
 * Read-only twin of ApplySafeFixesJob's fix computation, for the "show me
 * the code, I'll paste it myself" path - never calls ThemeAssetService::write().
 * Shares the exact transform functions ApplySafeFixesJob uses (via
 * ThemeAssetLocatorService::deferScriptTag() and ImageLazyLoadSweeper::sweep(),
 * both already write-free) so the code shown here is provably identical to
 * what auto-fix would have applied, not a second implementation that could
 * silently drift from it.
 */
class SafeFixCodeService
{
    public function __construct(
        private readonly ThemeAssetService $themeAssets,
        private readonly ThemeAssetLocatorService $locator,
        private readonly ImageLazyLoadSweeper $sweeper,
        private readonly FontDisplaySweeper $fontSweeper,
    ) {
    }

    /**
     * @return array{fix_type: string, files: array<int, array{asset_key: string, original: string, fixed: string}>, truncated_count: int}
     */
    public function code(AuditIssue $issue): array
    {
        $fixType = $issue->meta['fix_type'] ?? null;

        return match ($fixType) {
            'defer_script' => $this->deferScriptCode($issue),
            'lazy_load_sweep' => $this->sweepCode($this->sweeper, 'lazy_load_sweep', 'No plain <img> tags without loading="lazy" were found in your theme right now.'),
            'font_display_sweep' => $this->sweepCode($this->fontSweeper, 'font_display_sweep', "No font_face calls missing font-display: 'swap' were found in your theme right now."),
            default => throw new RuntimeException('This issue has no manual fix code available.'),
        };
    }

    private function deferScriptCode(AuditIssue $issue): array
    {
        $scriptSrc = $issue->meta['script_src'] ?? null;

        if (! $scriptSrc) {
            throw new RuntimeException('No script source was recorded for this issue.');
        }

        $liveThemeId = $this->themeAssets->activeThemeId();

        if (! $liveThemeId) {
            throw new RuntimeException('Could not access your theme right now.');
        }

        $assetKey = $this->locator->findTagSource($liveThemeId, $scriptSrc);

        if (! $assetKey) {
            throw new RuntimeException(
                "Couldn't find this script directly in your theme's files - it's most likely injected by an ".
                "app rather than hardcoded in the theme, which means there's no theme code to show you here."
            );
        }

        $original = $this->themeAssets->read($liveThemeId, $assetKey);

        if ($original === null) {
            throw new RuntimeException('Could not read this theme file.');
        }

        $fixed = ThemeAssetLocatorService::deferScriptTag($original, $scriptSrc);

        if ($fixed === $original) {
            throw new RuntimeException("Found the file but couldn't locate the exact script tag inside it.");
        }

        return [
            'fix_type' => 'defer_script',
            'files' => [['asset_key' => $assetKey, 'original' => $original, 'fixed' => $fixed]],
            'truncated_count' => 0,
        ];
    }

    private function sweepCode(ImageLazyLoadSweeper|FontDisplaySweeper $sweeper, string $fixType, string $emptyMessage): array
    {
        $liveThemeId = $this->themeAssets->activeThemeId();

        if (! $liveThemeId) {
            throw new RuntimeException('Could not access your theme right now.');
        }

        $changed = $sweeper->sweep($liveThemeId);

        if (empty($changed)) {
            throw new RuntimeException($emptyMessage);
        }

        // A theme-wide sweep can touch dozens of section/snippet files at
        // once - showing all of them as copy-paste blocks would be
        // unusable, so cap the display and say how many more there are.
        $shown = array_slice($changed, 0, 5, preserve_keys: true);

        return [
            'fix_type' => $fixType,
            'files' => array_map(
                fn (string $assetKey, array $change) => ['asset_key' => $assetKey, 'original' => $change['original'], 'fixed' => $change['updated']],
                array_keys($shown),
                array_values($shown),
            ),
            'truncated_count' => max(0, count($changed) - count($shown)),
        ];
    }
}
