<?php

namespace App\Services\Shopify;

/**
 * "Below-the-fold image not lazy-loaded" can't be targeted at one exact
 * image the way a script fix can (a flagged image's rendered CDN URL almost
 * never appears verbatim in the theme's Liquid source - it's built at
 * render time from image_url filters). Adding loading="lazy" to every plain
 * <img> tag across the theme's sections/snippets is safe and additive
 * regardless of which one Lighthouse flagged, so this sweeps all of them at
 * once instead of trying to locate a single asset_key.
 */
class ImageLazyLoadSweeper
{
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
            fn (string $f) => str_ends_with($f, '.liquid')
                && (str_starts_with($f, 'sections/') || str_starts_with($f, 'snippets/'))
        ));

        $changed = [];

        foreach (array_chunk($candidates, 50) as $batch) {
            foreach ($this->assets->readMany($themeId, $batch) as $filename => $content) {
                if ($content === null) {
                    continue;
                }

                $updated = $this->addLazyLoading($content);

                if ($updated !== $content) {
                    $changed[$filename] = ['original' => $content, 'updated' => $updated];
                }
            }
        }

        return $changed;
    }

    private function addLazyLoading(string $content): string
    {
        // A plain [^>]* attribute match breaks the moment an attribute
        // VALUE contains a literal '>' - which Shopify sections do
        // constantly, e.g. a multi-line srcset built from
        // {%- if image.width >= 750 -%} conditionals. That '>' inside the
        // quoted string used to end the match early, splicing
        // loading="lazy"> into the middle of the Liquid comparison instead
        // of at the tag's real close (confirmed against a real, live
        // theme file - this wasn't hypothetical). Treating a quoted string
        // as one atomic unit, '>' and all, is what a real HTML tokenizer
        // does; this is the minimal regex equivalent of that.
        $attr = '(?:"[^"]*"|\'[^\']*\'|[^">])*?';

        return preg_replace_callback(
            '/<img\b(?!'.$attr.'\bloading\s*=)('.$attr.')(\/?)>/i',
            fn (array $m) => '<img'.$m[1].' loading="lazy"'.$m[2].'>',
            $content,
        ) ?? $content;
    }
}
