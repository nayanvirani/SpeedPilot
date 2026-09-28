<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use MatthiasMullie\Minify\JS;
use Throwable;

/**
 * Strips comments/whitespace (not full obfuscation - no variable renaming,
 * which needs a real JS parser/AST tool this PHP-only backend doesn't have)
 * from the storefront-hosted engines (interceptor.js, prefetch.js) before
 * they're served to real visitors' browsers, so the source isn't just
 * sitting there readable/copy-pasteable in a page's "View source". Never
 * breaks the actual feature over this: any minifier failure falls back to
 * the original, unminified source rather than serving a broken script.
 */
class JsMinifier
{
    public static function minify(string $js): string
    {
        try {
            return (new JS)->add($js)->minify();
        } catch (Throwable $e) {
            Log::warning('JS minification failed, serving unminified source', ['message' => $e->getMessage()]);

            return $js;
        }
    }
}
