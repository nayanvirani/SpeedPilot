<?php

namespace App\Services\Shopify;

/**
 * Same reasoning as ImageLazyLoadSweeper: a flagged font's rendered URL is
 * built at render time from a font_face filter call, not something that
 * appears verbatim in the theme source to target one specific instance.
 * Adding font-display: swap to every font_face call theme-wide is safe and
 * additive regardless of which font Lighthouse flagged - unlike a CSS/JS
 * defer choice, there's no real downside to font-display: swap (Google's
 * own recommended default), so this stays a blanket sweep rather than
 * needing a preview step.
 *
 * Scans sections/snippets/templates/layout (not just sections/snippets like
 * the image sweeper) - fonts are almost always declared once in
 * layout/theme.liquid's <head>, which the image sweeper never looks at.
 */
class FontDisplaySweeper
{
    private const SEARCHABLE_PREFIXES = ['sections/', 'snippets/', 'templates/', 'layout/'];

    public function __construct(private readonly ThemeAssetService $assets)
    {
    }

    /**
     * @return array<string, array{original: string, updated: string}> filename => change, for files actually modified
     */
    public function sweep(string $themeId): array
    {
        $candidates = array_values(array_filter(
            $this->assets->listFilenames($themeId),
            fn (string $f) => str_ends_with($f, '.liquid') && $this->hasSearchablePrefix($f)
        ));

        $changed = [];

        foreach (array_chunk($candidates, 50) as $batch) {
            foreach ($this->assets->readMany($themeId, $batch) as $filename => $content) {
                if ($content === null) {
                    continue;
                }

                $updated = $this->addFontDisplaySwap($content);

                if ($updated !== $content) {
                    $changed[$filename] = ['original' => $content, 'updated' => $updated];
                }
            }
        }

        return $changed;
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
     * Matches {{ <expr> | font_face }} or {{ <expr> | font_face: ...params }}
     * and inserts font_display: 'swap' when it isn't already one of the
     * params. Shopify's docs only document font_display as a parameter, but
     * the comma-append path is kept in case a theme ever adds another one.
     */
    private function addFontDisplaySwap(string $content): string
    {
        return preg_replace_callback(
            '/\{\{(-?)\s*([^|{}]+?)\s*\|\s*font_face\b([^}]*?)\s*(-?)\}\}/i',
            function (array $m) {
                [, $trimStart, $expr, $params, $trimEnd] = $m;

                if ($params !== '' && stripos($params, 'font_display') !== false) {
                    return $m[0];
                }

                $newParams = $params === ''
                    ? ": font_display: 'swap'"
                    : rtrim($params).", font_display: 'swap'";

                return '{{'.$trimStart.' '.$expr.' | font_face'.$newParams.' '.$trimEnd.'}}';
            },
            $content,
        ) ?? $content;
    }
}
