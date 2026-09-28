<?php

namespace App\Services\Shopify;

/**
 * The scanner (Lighthouse) only sees the rendered page, not which theme file
 * produced a given element - it has no Shopify API access to look. This
 * resolves a flagged render-blocking resource's rendered URL (a <script src>
 * or a <link href>) back to the actual theme file that hardcodes it, so
 * ApplySafeFixesJob/MediumFixService have a real asset_key to edit instead of
 * skipping the fix entirely. Dynamically generated image URLs (Liquid's
 * image_url filter) aren't searchable this way - a rendered CDN URL for an
 * image essentially never appears verbatim in the theme's own source - so
 * this is scoped to scripts and stylesheets, which are almost always a
 * literal hardcoded external URL when an app or theme embeds one directly
 * rather than generating it at render time.
 */
class ThemeAssetLocatorService
{
    private const SEARCHABLE_PREFIXES = ['sections/', 'snippets/', 'templates/', 'layout/'];

    public function __construct(private readonly ThemeAssetService $assets)
    {
    }

    public function findTagSource(string $themeId, string $url): ?string
    {
        $needle = self::needleFor($url);

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
     * CSS-specific: unlike a script (often a dynamic app endpoint with no
     * real filename - see needleFor()'s hostname fallback), a theme's own
     * stylesheet is basically always a real, distinctly-named static file,
     * so this never falls back to matching by hostname alone - a bare
     * hostname can appear anywhere in a theme file (a social link, a
     * canonical tag) and would silently pick the wrong one. Confirmed live:
     * searching for "main.css" via the hostname fallback matched
     * sections/footer.liquid, a file that never referenced main.css at all.
     *
     * Also unlike a script tag, a theme's critical CSS is commonly rendered
     * via Shopify's own `stylesheet_tag` filter
     * (`{{ 'main.css' | asset_url | stylesheet_tag:preload:true }}`), which
     * never appears as HTML in the source at all - just the plain filename
     * as a quoted Liquid string argument. Searching for the bare basename
     * (no quotes, no host) catches both that case and a literal hardcoded
     * `<link href="...">`, since both contain the filename as a substring;
     * deferStylesheetTag() below handles rewriting whichever shape it turns
     * out to be.
     */
    public function findStylesheetSource(string $themeId, string $cssUrl): ?string
    {
        $basename = basename(parse_url($cssUrl, PHP_URL_PATH) ?? '');

        if ($basename === '') {
            return null;
        }

        foreach (array_chunk($this->searchableFilenames($themeId), 50) as $batch) {
            foreach ($this->assets->readMany($themeId, $batch) as $filename => $content) {
                if ($content !== null && str_contains($content, $basename)) {
                    return $filename;
                }
            }
        }

        return null;
    }

    /**
     * A stylesheet Lighthouse flags on the rendered page is, unlike an
     * app-injected script, almost always a literal theme asset served
     * straight from the theme's own file tree (assets/*.css) - so this
     * matches the URL's basename directly against the theme's real asset
     * list instead of text-searching Liquid source for a needle. Exact
     * match only: a basename collision with an unrelated file would silently
     * edit the wrong stylesheet.
     */
    public function findAssetByBasename(string $themeId, string $url): ?string
    {
        $basename = basename(parse_url($url, PHP_URL_PATH) ?? '');

        if ($basename === '') {
            return null;
        }

        foreach ($this->assets->listFilenames($themeId) as $filename) {
            if (basename($filename) === $basename) {
                return $filename;
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

    /**
     * The one place this edit is computed - both ApplySafeFixesJob's
     * auto-apply path and the read-only "show me the code" fallback call
     * this, so they can never silently drift apart the way the locate/edit
     * needle once did.
     */
    public static function deferScriptTag(string $content, string $scriptSrc): string
    {
        $needle = preg_quote(self::needleFor($scriptSrc), '/');

        return preg_replace(
            '/<script([^>]*src=["\'][^"\']*'.$needle.'[^"\']*["\'][^>]*)>/i',
            '<script$1 defer>',
            $content,
            1,
        ) ?? $content;
    }

    /**
     * The standard, well-documented async-CSS pattern (also Lighthouse's own
     * "Eliminate render-blocking resources" guidance): media="print" makes
     * the browser fetch it without blocking render, onload swaps it to "all"
     * once loaded so the styles actually apply, and the <noscript> fallback
     * keeps the original blocking tag for clients with JS disabled. Never
     * removes the stylesheet or its styles - only delays when it takes
     * effect, same trade-off already accepted for deferred scripts.
     *
     * Tries two shapes, in order - confirmed live that both are real, common
     * cases, not hypothetical:
     *
     * 1. Shopify's own `stylesheet_tag` filter output
     *    (`{{ 'main.css' | asset_url | stylesheet_tag:preload:true }}`) -
     *    this is what Dawn-based themes actually use for their critical CSS
     *    (confirmed against a real store: main.css, base.css, and most of
     *    its other render-blocking stylesheets all use this, not a literal
     *    <link> tag). stylesheet_tag has no parameter for media/onload, so
     *    the only way to make it async is to replace the whole filter call
     *    with literal HTML built from the same asset_url call.
     * 2. A literal hardcoded `<link rel="stylesheet" href="...">` tag -
     *    matched via lookaheads for rel and href in either order, since real
     *    theme markup isn't consistent about attribute order.
     */
    public static function deferStylesheetTag(string $content, string $cssUrl): string
    {
        $basenameRaw = basename(parse_url($cssUrl, PHP_URL_PATH) ?? '');
        $basename = preg_quote($basenameRaw, '/');

        $stylesheetTagPattern = '/\{\{-?\s*[\'"]'.$basename.'[\'"]\s*\|\s*asset_url\s*\|\s*stylesheet_tag[^}]*-?\}\}/i';
        $asyncTag = "<link rel=\"stylesheet\" href=\"{{ '{$basenameRaw}' | asset_url }}\" media=\"print\" onload=\"this.media='all'\">";
        $noscriptTag = "<noscript><link rel=\"stylesheet\" href=\"{{ '{$basenameRaw}' | asset_url }}\"></noscript>";

        $replaced = preg_replace($stylesheetTagPattern, $asyncTag."\n".$noscriptTag, $content, 1);

        if ($replaced !== null && $replaced !== $content) {
            return $replaced;
        }

        $needle = preg_quote(self::needleFor($cssUrl), '/');

        return preg_replace_callback(
            '/<link\b(?=[^>]*\brel=["\']stylesheet["\'])(?=[^>]*\bhref=["\'][^"\']*'.$needle.'[^"\']*["\'])([^>]*)>/i',
            function (array $m) {
                $original = '<link'.$m[1].'>';
                $async = preg_replace('/\smedia=["\'][^"\']*["\']/i', '', $original);
                $async = rtrim(substr($async, 0, -1)).' media="print" onload="this.media=\'all\'">';

                return $async."\n<noscript>{$original}</noscript>";
            },
            $content,
            1,
        ) ?? $content;
    }
}
