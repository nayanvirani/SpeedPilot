<?php

namespace App\Services\Shopify;

/**
 * The scanner (Lighthouse) only sees the rendered page, not which theme file
 * produced a given element - it has no Shopify API access to look. This
 * resolves a flagged render-blocking script's rendered <script src> back to
 * the actual theme file that hardcodes it, so ApplySafeFixesJob has a real
 * asset_key to edit instead of skipping the fix entirely. Dynamically
 * generated image URLs (Liquid's image_url filter) aren't searchable this
 * way - a rendered CDN URL for an image essentially never appears verbatim
 * in the theme's own source - so this is scoped to scripts, which are almost
 * always a literal hardcoded external URL when an app embeds one directly
 * in the theme rather than via the Script Tag API.
 */
class ThemeAssetLocatorService
{
    private const SEARCHABLE_PREFIXES = ['sections/', 'snippets/', 'templates/', 'layout/'];

    public function __construct(private readonly ThemeAssetService $assets)
    {
    }

    public function findScriptSource(string $themeId, string $scriptSrc): ?string
    {
        $needle = $this->tailOf($scriptSrc);

        if ($needle === '') {
            return null;
        }

        foreach (array_chunk($this->searchableFilenames($themeId), 50) as $batch) {
            foreach ($this->assets->readMany($themeId, $batch) as $filename => $content) {
                if ($content !== null && str_contains($content, $needle)) {
                    return $filename;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function searchableFilenames(string $themeId): array
    {
        return array_values(array_filter(
            $this->assets->listFilenames($themeId),
            fn (string $f) => str_ends_with($f, '.liquid') && $this->hasSearchablePrefix($f)
        ));
    }

    private function hasSearchablePrefix(string $filename): bool
    {
        foreach (self::SEARCHABLE_PREFIXES as $prefix) {
            if (str_starts_with($filename, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A rendered script tag is usually an absolute URL with a query string
     * (cache-busting version params etc.) that the theme source won't
     * contain verbatim - the file path itself is the stable, searchable
     * part.
     */
    private function tailOf(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? $url;

        return basename($path);
    }
}
