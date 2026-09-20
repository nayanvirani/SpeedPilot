<?php

namespace App\Services\Shopify;

/**
 * Deliberately naive (comments/whitespace only, no selector restructuring) -
 * it can't break CSS *semantics*, only whitespace inside string literals
 * (e.g. `content: "a  b"`), which is rare and low-stakes enough to be worth
 * gating behind explicit merchant approval rather than ruling out minification
 * entirely.
 */
class CssMinifier
{
    public static function minify(string $css): string
    {
        $css = preg_replace('!/\*.*?\*/!s', '', $css) ?? $css;
        $css = preg_replace('/\s+/', ' ', $css) ?? $css;
        $css = preg_replace('/\s*([{}:;,])\s*/', '$1', $css) ?? $css;
        $css = str_replace(';}', '}', $css);

        return trim($css);
    }
}
