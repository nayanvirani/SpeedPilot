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
        $needle = self::needleFor($scriptSrc);

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
     * The single source of truth for "what substring of this URL would
     * plausibly appear verbatim in a hardcoded <script src>" - used both to
     * locate the file here and, by callers that already have a located
     * asset_key, to build the same regex needle for the actual tag edit.
     * They must stay identical: a locator/editor needle mismatch means
     * "found the file but couldn't locate the tag inside it" even when the
     * file and tag are both right there.
     *
     * A rendered script tag is usually an absolute URL with a query string
     * (cache-busting version params etc.) the theme source won't contain
     * verbatim. The path's basename works for a real static asset (a long,
     * specific filename), but plenty of tracking scripts are dynamic
     * endpoints with no real filename at all - Google Tag Manager's is
     * literally just "js" (/gtag/js?id=...), which matched something as
     * unrelated as class="no-js" in layout/password.liquid before this
     * existed. The hostname is the one part of the URL a script tag can't
     * omit and a vendor essentially never changes, so it's the more
     * reliable anchor whenever the basename isn't specific enough to trust
     * alone.
     */
    public static function needleFor(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        $basename = basename(parse_url($url, PHP_URL_PATH) ?? '');

        // A real static asset filename (hashed/versioned JS files are
        // typically 20+ characters) is specific enough to search on alone.
        // Anything shorter - "js", "hop", "index.js" - is too generic and
        // falls back to the hostname instead.
        return strlen($basename) >= 12 ? $basename : $host;
    }
}
