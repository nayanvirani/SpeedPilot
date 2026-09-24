<?php

namespace App\Services\Shopify;

use App\Models\AppImpact;
use App\Models\ContentStopTarget;
use App\Models\ShopInstallation;

/**
 * The real work behind the App & Script Impact page's Disable/Delay
 * actions - previously these only flipped a status label with no effect on
 * the actual storefront. Both require locating the script directly in the
 * theme's Liquid source (ThemeAssetLocatorService, same mechanism as the
 * render-blocking-script safe fix) - a script injected by another app via
 * Shopify's Script Tag API isn't theme-editable at all, so that case is
 * reported honestly rather than faked as success.
 */
class ScriptImpactActionService
{
    public function __construct(
        private readonly ThemeAssetService $themeAssets,
        private readonly ThemeAssetLocatorService $locator,
        private readonly AssetBackupService $backups,
    ) {
    }

    /**
     * @return array{applied: bool, message: ?string}
     */
    public function disable(ShopInstallation $shop, AppImpact $impact): array
    {
        return $this->applyEdit($shop, $impact, 'disabled', self::disableTransform(...));
    }

    /**
     * @return array{applied: bool, message: ?string}
     */
    public function delay(ShopInstallation $shop, AppImpact $impact): array
    {
        return $this->applyEdit($shop, $impact, 'delayed', self::delayTransform(...));
    }

    /**
     * Read-only twin of disable()/delay() - resolves and computes the exact
     * same edit but never writes it anywhere, for a "copy this code
     * yourself" fallback while theme writes are blocked (see
     * ThemeWriteAccessDeniedException) or for a merchant who'd rather review
     * before SpeedPilot touches anything.
     *
     * @return array{asset_key: ?string, original: ?string, fixed: ?string, error: ?string}
     */
    public function preview(ShopInstallation $shop, AppImpact $impact, string $action): array
    {
        $transform = $action === 'delayed' ? self::delayTransform(...) : self::disableTransform(...);

        return $this->resolveAndTransform($shop, $impact, $transform);
    }

    private static function disableTransform(string $content, string $needle): string
    {
        $pattern = '/<script\b[^>]*src=["\'][^"\']*'.$needle.'[^"\']*["\'][^>]*>.*?<\/script>/is';

        return preg_replace($pattern, '', $content, 1) ?? $content;
    }

    private static function delayTransform(string $content, string $needle): string
    {
        $pattern = '/<script\b[^>]*\bsrc=["\']([^"\']*'.$needle.'[^"\']*)["\'][^>]*>.*?<\/script>/is';

        return preg_replace_callback($pattern, fn (array $m) => self::delayedLoaderSnippet($m[1]), $content, 1) ?? $content;
    }

    /**
     * @return array{applied: bool, message: ?string, blocked: bool}
     */
    public function restore(ShopInstallation $shop, AppImpact $impact): array
    {
        if ($impact->delay_method === 'interceptor') {
            return $this->restoreInterceptor($shop, $impact);
        }

        if ($impact->delay_method === 'content_replace') {
            return $this->restoreContentMatch($shop, $impact);
        }

        $optimization = $shop->optimizations()
            ->where('app_impact_id', $impact->id)
            ->where('status', 'applied')
            ->latest()
            ->first();

        if (! $optimization) {
            // Nothing was ever actually applied (e.g. coming back from
            // "excluded", which never touches the theme) - there's nothing
            // to restore, and that's fine.
            return ['applied' => true, 'message' => null, 'blocked' => false];
        }

        try {
            $restored = $this->backups->restore($optimization, $this->themeAssets);
        } catch (ThemeWriteAccessDeniedException) {
            $shop->update(['theme_write_blocked_at' => now()]);

            return [
                'applied' => false,
                'message' => "Couldn't restore this automatically right now - you may need to revert it ".
                    "manually in Shopify's theme editor.",
                'blocked' => true,
            ];
        }

        return [
            'applied' => $restored,
            'message' => $restored ? null : 'Could not restore the original script automatically.',
            'blocked' => false,
        ];
    }

    // extensions/rum-snippet - same Theme App Extension the RUM web-vitals
    // snippet uses, just a second app-embed block in it.
    public const EMBED_UUID = '173eac1f-9228-9b2c-0d6c-90479e7e9644e74d53e7';

    public const EMBED_HANDLE = 'advanced-delay-snippet';

    /**
     * The "Advanced delay (experimental)" action - for a script SpeedPilot
     * can't find in theme files at all (Shopify ScriptTag-injected), this
     * doesn't edit the app's own tag (impossible - it isn't theme content).
     *
     * The engine itself is NOT written into the merchant's theme, and
     * SpeedPilot never edits theme.liquid for this feature at all - the
     * <script src> tag pointing at /storefront/interceptor.js
     * (InterceptorController) is delivered by a Theme App Extension app
     * embed (extensions/rum-snippet/blocks/advanced-delay-snippet.liquid),
     * the same mechanism the RUM web-vitals snippet already uses. The
     * merchant switches it on once in Theme Editor > App embeds; Shopify
     * renders the tag on every storefront page from then on, and removes it
     * the moment they switch it off - no write_themes access needed for
     * this feature at all, and no separate cleanup step on uninstall
     * (Shopify disables an uninstalled app's embeds automatically).
     *
     * Toggling a delay on/off here only ever changes the DB row - the
     * hosted endpoint reads this shop's current delay list live on every
     * request, so there is nothing else to write anywhere.
     *
     * @return array{applied: bool, message: ?string, blocked: bool}
     */
    public function interceptorDelay(ShopInstallation $shop, AppImpact $impact): array
    {
        if (! $impact->script_url) {
            return ['applied' => false, 'message' => "No script URL was recorded for this app, so it can't be targeted.", 'blocked' => false];
        }

        $shop->interceptorDelayTargets()->firstOrCreate(['script_url' => $impact->script_url]);

        return [
            'applied' => true,
            'message' => "Make sure the \"SpeedPilot Advanced Delay\" app embed is turned on in Theme Editor > ".
                'App embeds - the delay only takes effect on your storefront once it is.',
            'blocked' => false,
        ];
    }

    /**
     * Just removes this shop's target - the app embed block itself stays
     * (harmless, and the hosted endpoint naturally starts returning a no-op
     * script once a shop has no targets left, so there's nothing to revert
     * anywhere else - see the class docblock above).
     *
     * @return array{applied: bool, message: ?string, blocked: bool}
     */
    public function restoreInterceptor(ShopInstallation $shop, AppImpact $impact): array
    {
        if ($impact->script_url) {
            $shop->interceptorDelayTargets()->where('script_url', $impact->script_url)->delete();
        }

        return ['applied' => true, 'message' => null, 'blocked' => false];
    }

    /**
     * Deep link straight to this shop's Theme Editor with the "SpeedPilot
     * Advanced Delay" app embed panel open, so a merchant doesn't have to
     * hunt for it manually under Online Store > Themes > Customize > App
     * embeds.
     */
    public static function interceptorEmbedDeepLink(ShopInstallation $shop): string
    {
        return 'https://'.$shop->shop_domain.'/admin/themes/current/editor'
            .'?context=apps&activateAppId='.self::EMBED_UUID.'/'.self::EMBED_HANDLE;
    }

    private const CONTENT_STOP_START = '<!-- SpeedPilot:content-stop:start -->';

    private const CONTENT_STOP_END = '<!-- SpeedPilot:content-stop:end -->';

    private const CONTENT_FOR_HEADER_NEEDLES = ['{{- content_for_header -}}', '{{ content_for_header }}', '{{content_for_header}}'];

    /**
     * "Stop (verified)" - for an app whose script SpeedPilot has actually
     * observed, this scan, as literal text in this shop's rendered
     * content_for_header (AppImpact::content_for_header_match, set by the
     * scanner's own real HTTP fetch of the storefront - never assumed from
     * the URL alone). Renames the matched src/href attribute to an inert
     * one via a Liquid `replace` chain applied directly to content_for
     * _header in theme.liquid, the same real technique a working reference
     * theme uses for its own (different) apps - server-side, before
     * Shopify ever sends HTML to the browser, so there's no race with the
     * browser's own parser the way the client-side interceptor has.
     *
     * Deliberately NOT offered when content_for_header_match is null - most
     * visibly, Shopify's own six built-in marketing pixels (Facebook, GTM,
     * Affirm, Klarna, TikTok, shop.app/pay) are loaded through Shopify's
     * sandboxed Web Pixels Manager, never present as literal text anywhere
     * in the page, confirmed by fetching a real storefront's HTML directly
     * this session - no `replace` filter, Service Worker, or client JS can
     * ever touch those, so this refuses honestly instead of writing a
     * `replace` pair that would silently never match anything.
     *
     * @return array{applied: bool, message: ?string, blocked: bool}
     */
    public function stopContentMatch(ShopInstallation $shop, AppImpact $impact): array
    {
        if (! $impact->script_url) {
            return ['applied' => false, 'message' => "No script URL was recorded for this app, so it can't be targeted.", 'blocked' => false];
        }

        if (! $impact->content_for_header_match) {
            return [
                'applied' => false,
                'message' => "SpeedPilot couldn't find this script as literal code in your storefront's rendered "
                    .'page during the last scan - it may be loaded through Shopify\'s own sandboxed Web Pixels '
                    ."system, which no app (including this one) can intercept. Run a fresh scan if this app's ".
                    'script delivery has changed.',
                'blocked' => false,
            ];
        }

        if (! $shop->target_theme_id) {
            return ['applied' => false, 'message' => 'Pick which theme SpeedPilot should apply changes to first (Dashboard > Target theme).', 'blocked' => false];
        }

        $target = $shop->contentStopTargets()->updateOrCreate(
            ['script_url' => $impact->script_url],
            ['source_snippet' => $impact->content_for_header_match],
        );

        $result = $this->rewriteContentForHeaderBlock($shop);

        // If the write never actually landed (most likely write_themes
        // isn't approved yet for this shop), don't leave a target row
        // behind - the next scan would otherwise report this app as
        // "delayed" purely because the row exists, even though
        // theme.liquid was never touched.
        if (! $result['applied'] && $target->wasRecentlyCreated) {
            $target->delete();
        }

        return $result;
    }

    /**
     * @return array{applied: bool, message: ?string, blocked: bool}
     */
    public function restoreContentMatch(ShopInstallation $shop, AppImpact $impact): array
    {
        if ($impact->script_url) {
            $shop->contentStopTargets()->where('script_url', $impact->script_url)->delete();
        }

        return $this->rewriteContentForHeaderBlock($shop);
    }

    /**
     * Rebuilds the ENTIRE content-stop block from every current
     * content_stop_targets row for this shop and writes it in one shot -
     * not an incremental patch, same idempotent-block approach the old
     * interceptor tag installer used. Removes the block entirely (falling
     * back to plain content_for_header) once no targets remain, rather than
     * leaving a no-op replace chain sitting in the theme.
     *
     * @return array{applied: bool, message: ?string, blocked: bool}
     */
    private function rewriteContentForHeaderBlock(ShopInstallation $shop): array
    {
        $themeId = $shop->target_theme_id;
        $assetKey = 'layout/theme.liquid';

        $original = $this->themeAssets->read($themeId, $assetKey);

        if ($original === null) {
            return ['applied' => false, 'message' => 'Could not read your theme.liquid file.', 'blocked' => false];
        }

        // Strip out any existing block first (and the needle it wraps),
        // leaving a clean insertion point behind - every rewrite starts
        // from the same known-good baseline instead of patching whatever
        // is currently there.
        $needle = self::CONTENT_FOR_HEADER_NEEDLES[1];
        $blockPattern = '/'.preg_quote(self::CONTENT_STOP_START, '/').'.*?'.preg_quote(self::CONTENT_STOP_END, '/').'/s';
        $base = preg_replace($blockPattern, $needle, $original) ?? $original;

        $targets = $shop->contentStopTargets()->get();

        if ($targets->isEmpty()) {
            $updated = $base;
        } else {
            $foundNeedle = null;

            foreach (self::CONTENT_FOR_HEADER_NEEDLES as $candidate) {
                if (str_contains($base, $candidate)) {
                    $foundNeedle = $candidate;
                    break;
                }
            }

            if ($foundNeedle === null) {
                return ['applied' => false, 'message' => "Couldn't find a safe place to add this to your theme.liquid - contact SpeedPilot support.", 'blocked' => false];
            }

            $block = $this->buildContentStopBlock($targets);
            $updated = str_replace($foundNeedle, $block, $base);
        }

        if ($updated === $original) {
            return ['applied' => true, 'message' => null, 'blocked' => false];
        }

        $optimization = $shop->optimizations()
            ->where('type', 'content_stop')
            ->where('status', 'recommended')
            ->first();

        if (! $optimization) {
            $optimization = $shop->optimizations()->create([
                'type' => 'content_stop',
                'risk_tier' => 'medium',
                'status' => 'recommended',
                'theme_id' => $themeId,
                'asset_key' => $assetKey,
            ]);
        }

        $this->backups->backup($optimization, $themeId, $assetKey, $original, $updated);

        try {
            $this->themeAssets->write($themeId, $assetKey, $updated);
        } catch (ThemeWriteAccessDeniedException) {
            $shop->update(['theme_write_blocked_at' => now()]);

            return [
                'applied' => false,
                'message' => "Couldn't update your theme automatically right now - try again once SpeedPilot's theme-editing access is approved.",
                'blocked' => true,
            ];
        }

        $shop->update(['theme_write_blocked_at' => null]);
        $optimization->update(['status' => 'applied', 'applied_at' => now()]);

        return ['applied' => true, 'message' => null, 'blocked' => false];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ContentStopTarget>  $targets
     */
    private function buildContentStopBlock($targets): string
    {
        $filters = $targets->map(function (ContentStopTarget $target) {
            $search = $target->source_snippet;
            $replace = preg_replace('/^(src|href)(\s*=)/i', 'data-speedpilot-$1$2', $search, 1) ?? $search;

            return '| replace: '.self::liquidStringLiteral($search).', '.self::liquidStringLiteral($replace);
        })->implode("\n  ");

        return self::CONTENT_STOP_START."\n"
            .'{%- assign speedpilot_header = content_for_header'."\n  ".$filters." -%}\n"
            .'{{ speedpilot_header }}'."\n"
            .self::CONTENT_STOP_END;
    }

    /**
     * Liquid string literals don't support escaping an embedded quote, so
     * wrap in whichever quote character doesn't appear in the value - safe
     * here because every value is an HTML attribute snippet (src="..." or
     * src='...'), which by construction contains exactly one quote style,
     * never both.
     */
    private static function liquidStringLiteral(string $value): string
    {
        $quote = str_contains($value, '"') ? "'" : '"';

        return $quote.$value.$quote;
    }

    /**
     * The actual watcher engine - lives here so InterceptorController can
     * render it per-request with a shop's live delay list, never as a
     * static file. Best-effort, not guaranteed: catches scripts/
     * stylesheets/iframes *dynamically inserted* into the DOM by other JS
     * after page load (how most heavy third-party trackers actually load
     * their real payload) via MutationObserver, and delays them the same
     * way delayedLoaderSnippet() below does - same trigger set, for a
     * consistent merchant-facing experience regardless of which mechanism
     * ends up handling a given resource. Cannot intercept a resource that's
     * static markup in the initial HTML response (Shopify's own ScriptTag
     * rendering) - the browser dispatches that fetch as it parses the tag,
     * before any JS callback (including this one) gets a chance to run.
     *
     * Watches two distinct patterns real loaders use, not just one:
     * - src/href already set when the node is inserted (the common case -
     *   e.g. Google Tag Manager's own snippet sets j.src before
     *   insertBefore(j, f)) - caught via the childList mutation.
     * - an empty node inserted first, src/href assigned afterward via
     *   setAttribute - caught via the attributes mutation on the same
     *   observer. The fetch may already be in flight by the time that
     *   attribute change fires (same fundamental timing limit as anything
     *   client-side), but removing the node before it executes still has
     *   value even then.
     * Also matches iframes, not just script/link - several trackers
     * (Facebook Pixel, TikTok, some ad networks) load via iframe, not a
     * plain script tag.
     *
     * The whole thing is wrapped in try/catch: a bug here, or a DOM shape
     * this doesn't expect, must never be able to break anything else on the
     * merchant's storefront.
     *
     * Also releases anything the "Stop (verified)" content_for_header
     * `replace` mechanism neutralized server-side - those elements already
     * sit in the initial DOM with a `data-speedpilot-src`/`-href` attribute
     * instead of `src`/`href` (never fetched by the browser at all, unlike
     * the MutationObserver case above), so releasing them is just restoring
     * that attribute name on the SAME trigger set, no pattern matching
     * needed. This is deliberately the only place that release ever
     * happens - the stop itself is static theme code that survives an
     * uninstall, but since this script goes no-op the moment a shop is
     * inactive, an uninstalled shop's previously-stopped scripts stay
     * stopped rather than silently resuming, exactly as intended.
     *
     * @param  array<int, string>  $urls
     */
    public static function interceptorEngineJs(array $urls): string
    {
        $json = json_encode(array_values(array_unique($urls)), JSON_UNESCAPED_SLASHES);

        return <<<JS
            (function () {
              try {
                var PATTERNS = {$json};
                var released = false, pending = [];
                function matches(url) { return url && PATTERNS.some(function (p) { return url.indexOf(p) !== -1; }); }
                function releaseStopped() {
                  document.querySelectorAll('[data-speedpilot-src], [data-speedpilot-href]').forEach(function (node) {
                    if (node.hasAttribute('data-speedpilot-src')) {
                      node.setAttribute('src', node.getAttribute('data-speedpilot-src'));
                      node.removeAttribute('data-speedpilot-src');
                    }
                    if (node.hasAttribute('data-speedpilot-href')) {
                      node.setAttribute('href', node.getAttribute('data-speedpilot-href'));
                      node.removeAttribute('data-speedpilot-href');
                    }
                    var clone = document.createElement(node.tagName);
                    for (var i = 0; i < node.attributes.length; i++) clone.setAttribute(node.attributes[i].name, node.attributes[i].value);
                    if (node.parentNode) node.parentNode.replaceChild(clone, node);
                  });
                }
                function release() {
                  if (released) return;
                  released = true;
                  releaseStopped();
                  pending.forEach(function (node) {
                    if (!node.parentNode) return;
                    var clone = document.createElement(node.tagName);
                    for (var i = 0; i < node.attributes.length; i++) clone.setAttribute(node.attributes[i].name, node.attributes[i].value);
                    node.parentNode.replaceChild(clone, node);
                  });
                  pending = [];
                }
                function targetUrl(node) {
                  if (node.tagName === 'SCRIPT' || node.tagName === 'IFRAME') return node.src;
                  if (node.tagName === 'LINK' && node.rel === 'stylesheet') return node.href;
                  return null;
                }
                function handle(node) {
                  if (released || !node || !node.tagName || !node.parentNode) return;
                  if (pending.indexOf(node) !== -1) return;
                  var url = targetUrl(node);
                  if (matches(url)) {
                    node.remove();
                    pending.push(node);
                  }
                }
                ['mousemove', 'scroll', 'touchstart', 'keydown'].forEach(function (evt) {
                  window.addEventListener(evt, release, { once: true, passive: true });
                });
                setTimeout(release, 5000);
                new MutationObserver(function (mutations) {
                  if (released) return;
                  mutations.forEach(function (m) {
                    if (m.type === 'childList') {
                      (m.addedNodes || []).forEach(handle);
                    } else if (m.type === 'attributes') {
                      handle(m.target);
                    }
                  });
                }).observe(document.documentElement, {
                  childList: true,
                  subtree: true,
                  attributes: true,
                  attributeFilter: ['src', 'href'],
                });
              } catch (e) {
                // Never let a bug here take anything else down with it.
              }
            })();
            JS;
    }

    /**
     * @param  callable(string, string): string  $transform
     * @return array{applied: bool, message: ?string, blocked: bool}
     */
    private function applyEdit(ShopInstallation $shop, AppImpact $impact, string $action, callable $transform): array
    {
        ['asset_key' => $assetKey, 'original' => $original, 'fixed' => $updated, 'error' => $error]
            = $this->resolveAndTransform($shop, $impact, $transform);

        if ($error !== null) {
            return ['applied' => false, 'message' => $error, 'blocked' => false];
        }

        $writeThemeId = $shop->target_theme_id;

        $optimization = $shop->optimizations()->create([
            'app_impact_id' => $impact->id,
            'type' => $action,
            // Unlike the audit-issue safe fixes, this removes or delays
            // another app's own functionality on the storefront (not just
            // a performance attribute) - always at least medium risk.
            'risk_tier' => 'medium',
            'status' => 'recommended',
            'theme_id' => $writeThemeId,
            'asset_key' => $assetKey,
        ]);

        $this->backups->backup($optimization, $writeThemeId, $assetKey, $original, $updated);

        try {
            $this->themeAssets->write($writeThemeId, $assetKey, $updated);
        } catch (ThemeWriteAccessDeniedException) {
            $shop->update(['theme_write_blocked_at' => now()]);

            return [
                'applied' => false,
                'message' => "Couldn't apply this automatically right now - use Manual fix below to apply it yourself.",
                'blocked' => true,
            ];
        }

        $shop->update(['theme_write_blocked_at' => null]);
        $optimization->update(['status' => 'applied', 'applied_at' => now()]);

        return ['applied' => true, 'message' => null, 'blocked' => false];
    }

    /**
     * @param  callable(string, string): string  $transform
     * @return array{asset_key: ?string, original: ?string, fixed: ?string, error: ?string}
     */
    private function resolveAndTransform(ShopInstallation $shop, AppImpact $impact, callable $transform): array
    {
        $empty = ['asset_key' => null, 'original' => null, 'fixed' => null, 'error' => null];

        if (! $shop->target_theme_id) {
            return [...$empty, 'error' => 'Pick which theme SpeedPilot should apply changes to first (Dashboard > Target theme).'];
        }

        if (! $impact->script_url) {
            return [...$empty, 'error' => "No script URL was recorded for this app, so it can't be located automatically."];
        }

        // Issues were found scanning the live, rendered storefront, so
        // locating the flagged script has to happen against the live theme
        // regardless of where the fix gets written - a fresh preview
        // duplicate starts identical to it anyway.
        $liveThemeId = $this->themeAssets->activeThemeId();

        if (! $liveThemeId) {
            return [...$empty, 'error' => 'Could not access your theme right now - try again shortly.'];
        }

        $assetKey = $this->locator->findScriptSource($liveThemeId, $impact->script_url);

        if (! $assetKey) {
            return [...$empty, 'error' => "Couldn't find this script directly in your theme's files - it's most likely "
                ."injected by the app itself (e.g. via Shopify's Script Tag API), which SpeedPilot ".
                "can't edit. Try disabling the app from your Shopify admin, or contact the app's support."];
        }

        $original = $this->themeAssets->read($liveThemeId, $assetKey);

        if ($original === null) {
            return [...$empty, 'asset_key' => $assetKey, 'error' => 'Could not read the theme file.'];
        }

        // Must match the needle findScriptSource() used to locate $assetKey
        // in the first place - a different needle here risks "found the
        // file but not the tag inside it."
        $needle = preg_quote(ThemeAssetLocatorService::needleFor($impact->script_url), '/');
        $updated = $transform($original, $needle);

        if ($updated === $original) {
            return [...$empty, 'asset_key' => $assetKey, 'error' => "Found the file but couldn't locate the exact script tag inside it."];
        }

        return ['asset_key' => $assetKey, 'original' => $original, 'fixed' => $updated, 'error' => null];
    }

    private static function delayedLoaderSnippet(string $src): string
    {
        $jsonSrc = json_encode($src);

        return <<<HTML
            <script>
            (function () {
              var loaded = false;
              function speedpilotLoad() {
                if (loaded) return;
                loaded = true;
                var s = document.createElement('script');
                s.src = {$jsonSrc};
                document.body.appendChild(s);
              }
              ['mousemove', 'scroll', 'touchstart', 'keydown'].forEach(function (evt) {
                window.addEventListener(evt, speedpilotLoad, { once: true, passive: true });
              });
              setTimeout(speedpilotLoad, 5000);
            })();
            </script>
            HTML;
    }
}
