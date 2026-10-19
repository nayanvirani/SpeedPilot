<?php

namespace App\Services\Shopify;

use App\Models\ShopInstallation;
use GuzzleHttp\Client;
use Throwable;

/**
 * Self-hosts a theme's Google Fonts: downloads the real @font-face CSS and
 * every woff2 file Google serves for it, re-uploads them as theme assets,
 * and rewrites the theme's own tag to point at the self-hosted copy
 * instead. Eliminates the DNS lookup + connection + render-blocking
 * request to fonts.googleapis.com/fonts.gstatic.com every visitor
 * currently pays before a single font byte arrives - a well-established,
 * fully safe technique (no visual change, same font files, same
 * font-display/unicode-range behavior Google's own CSS already specifies).
 *
 * Only ever touches a Google Fonts reference that's literally present in
 * the theme's own Liquid source - a font loaded by an installed app via
 * content_for_header isn't theme-editable at all, the same limitation
 * ScriptImpactActionService::disable()/delay() already documents for
 * third-party scripts, reported honestly here rather than silently
 * skipped or faked as success.
 */
class GoogleFontsSelfHostService
{
    private const SEARCHABLE_PREFIXES = ['sections/', 'snippets/', 'templates/', 'layout/'];

    // Flat, not a subfolder - confirmed live against Shopify's real API
    // ("Theme files may not be stored in subfolders"), so every self-hosted
    // font file is a top-level assets/ entry with a distinguishing prefix
    // instead.
    private const ASSET_PREFIX = 'assets/speedpilot-font-';

    // Plain .css, not .css.liquid - confirmed live that Shopify serves an
    // uploaded asset's raw bytes as-is regardless of a .liquid suffix (no
    // Liquid processing happens, and asset_url does an exact filename
    // lookup, not a fuzzy one), so there's no reason for this to be
    // anything other than the real filename theme.liquid's asset_url call
    // below looks up directly.
    private const CSS_ASSET_KEY = 'assets/speedpilot-fonts.css';

    private Client $http;

    public function __construct(
        private readonly ThemeAssetService $assets,
        private readonly AssetBackupService $backups,
    ) {
        // A real browser UA, not Guzzle's default - Google serves different
        // font formats (woff2 vs. legacy woff/ttf) based on User-Agent, and
        // every modern browser gets woff2, which is what this needs.
        $this->http = new Client([
            'timeout' => 20,
            'headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'],
        ]);
    }

    /**
     * @return array{applied: bool, message: ?string, font_count?: int}
     */
    public function selfHost(ShopInstallation $shop): array
    {
        if (! $shop->target_theme_id) {
            return ['applied' => false, 'message' => 'Pick which theme SpeedPilot should apply changes to first (Dashboard > Target theme).'];
        }

        $themeId = $shop->target_theme_id;
        $located = $this->locateReference($themeId);

        if ($located === null) {
            return [
                'applied' => false,
                'message' => "Couldn't find a Google Fonts reference directly in your theme's files - it may be "
                    .'loaded by an installed app instead, which SpeedPilot can\'t rewrite this way (the same '
                    .'limitation as Disable/Delay for an app\'s own script).',
            ];
        }

        ['file' => $assetKey, 'fileContent' => $original, 'googleUrl' => $googleUrl] = $located;

        try {
            $googleCss = (string) $this->http->get($googleUrl)->getBody();
        } catch (Throwable) {
            return ['applied' => false, 'message' => "Couldn't reach Google Fonts right now - try again shortly."];
        }

        $fontUrls = $this->extractFontUrls($googleCss);

        if (empty($fontUrls)) {
            return ['applied' => false, 'message' => "Found the reference but couldn't parse any font files out of Google's response - nothing was changed."];
        }

        // Download + upload every font file before touching theme.liquid at
        // all - if any single file fails, the theme is never left pointing
        // at a self-hosted CSS asset with some fonts missing, which real
        // visitors would see as invisible/fallback text for whichever
        // weight or unicode range didn't make it.
        //
        // Each one's REAL public CDN URL is resolved right after upload
        // (ThemeAssetService::publicUrl()) and baked directly into the CSS
        // below as a plain url(...) - confirmed live that Shopify serves an
        // uploaded asset's bytes exactly as stored, with no Liquid
        // processing, so the CSS file itself can't contain `{{ ... |
        // asset_url }}` expecting them to resolve; only a real Liquid
        // template (theme.liquid, further down) actually renders those.
        $uploaded = [];

        foreach ($fontUrls as $url) {
            try {
                $bytes = (string) $this->http->get($url)->getBody();
            } catch (Throwable) {
                return ['applied' => false, 'message' => "Couldn't download one of the font files ({$url}) - nothing was changed."];
            }

            $filename = self::ASSET_PREFIX.basename(parse_url($url, PHP_URL_PATH) ?? '');
            $errors = $this->assets->writeBinary($themeId, $filename, $bytes);

            if (! empty($errors)) {
                return ['applied' => false, 'message' => 'Could not upload a font file to your theme: '.implode('; ', $errors)];
            }

            $publicUrl = $this->assets->publicUrl($themeId, $filename);

            if ($publicUrl === null) {
                return ['applied' => false, 'message' => "Uploaded a font file but couldn't resolve its CDN URL - nothing was changed in your theme's markup."];
            }

            $uploaded[$url] = $publicUrl;
        }

        $selfHostedCss = $this->rewriteCssUrls($googleCss, $uploaded);
        $this->assets->write($themeId, self::CSS_ASSET_KEY, $selfHostedCss);

        $tagReplacement = '<link rel="stylesheet" href="{{ \'speedpilot-fonts.css\' | asset_url }}">';
        $updatedFile = str_replace($located['matchedTag'], $tagReplacement, $original);

        if ($updatedFile === $original) {
            return ['applied' => false, 'message' => "Uploaded the fonts, but couldn't locate the exact tag to replace in {$assetKey} - contact SpeedPilot support."];
        }

        $optimization = $shop->optimizations()
            ->where('type', 'google_fonts_self_host')
            ->where('status', 'recommended')
            ->first();

        if (! $optimization) {
            $optimization = $shop->optimizations()->create([
                'type' => 'google_fonts_self_host',
                'risk_tier' => 'safe',
                'status' => 'recommended',
                'theme_id' => $themeId,
                'asset_key' => $assetKey,
            ]);
        }

        $this->backups->backup($optimization, $themeId, $assetKey, $original, $updatedFile);

        try {
            $this->assets->write($themeId, $assetKey, $updatedFile);
        } catch (ThemeWriteAccessDeniedException) {
            $shop->update(['theme_write_blocked_at' => now()]);

            return [
                'applied' => false,
                'message' => "Fonts were uploaded, but couldn't update your theme's tag automatically right now - "
                    .'try again once theme-editing access is approved.',
            ];
        }

        $shop->update(['theme_write_blocked_at' => null]);
        $optimization->update(['status' => 'applied', 'applied_at' => now()]);

        return ['applied' => true, 'message' => null, 'font_count' => count($uploaded)];
    }

    /**
     * Searches the same four theme-source directories every other locator
     * in this app treats as "real theme code" for either shape a Google
     * Fonts reference actually takes: a literal <link rel="stylesheet">
     * tag, or an @import inside a <style> block / CSS file.
     *
     * @return ?array{file: string, fileContent: string, googleUrl: string, matchedTag: string}
     */
    private function locateReference(string $themeId): ?array
    {
        $linkPattern = '/<link\b[^>]*href=["\']([^"\']*fonts\.googleapis\.com\/css[^"\']*)["\'][^>]*>/i';
        $importPattern = '/@import\s+url\((["\']?)(https:\/\/fonts\.googleapis\.com\/css[^"\')]*)\1\)\s*;?/i';

        $filenames = array_values(array_filter(
            $this->assets->listFilenames($themeId),
            fn (string $f) => (str_ends_with($f, '.liquid') || str_ends_with($f, '.css'))
                && $this->hasSearchablePrefix($f)
        ));

        foreach (array_chunk($filenames, 50) as $batch) {
            foreach ($this->assets->readMany($themeId, $batch) as $filename => $content) {
                if ($content === null) {
                    continue;
                }

                if (preg_match($linkPattern, $content, $m)) {
                    return ['file' => $filename, 'fileContent' => $content, 'googleUrl' => html_entity_decode($m[1]), 'matchedTag' => $m[0]];
                }

                if (preg_match($importPattern, $content, $m)) {
                    return ['file' => $filename, 'fileContent' => $content, 'googleUrl' => html_entity_decode($m[2]), 'matchedTag' => $m[0]];
                }
            }
        }

        return null;
    }

    private function hasSearchablePrefix(string $filename): bool
    {
        foreach (self::SEARCHABLE_PREFIXES as $prefix) {
            if (str_starts_with($filename, $prefix)) {
                return true;
            }
        }

        return str_starts_with($filename, 'assets/') && str_ends_with($filename, '.css');
    }

    /**
     * Google's CSS response carries one @font-face block per unicode-range
     * subset (confirmed live: a single family+weight can mean 10-20+
     * distinct woff2 files) - every url(...) inside a src: declaration is
     * a real, separate file that has to be downloaded and self-hosted, not
     * just the first one per font-family.
     *
     * @return array<int, string>
     */
    private function extractFontUrls(string $css): array
    {
        preg_match_all('/url\((https:\/\/fonts\.gstatic\.com\/[^)]+)\)/i', $css, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * @param  array<string, string>  $uploaded  gstatic URL => real public CDN URL
     */
    private function rewriteCssUrls(string $css, array $uploaded): string
    {
        foreach ($uploaded as $gstaticUrl => $publicUrl) {
            $css = str_replace($gstaticUrl, $publicUrl, $css);
        }

        return $css;
    }
}
