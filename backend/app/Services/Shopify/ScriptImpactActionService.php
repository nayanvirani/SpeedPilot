<?php

namespace App\Services\Shopify;

use App\Models\AppImpact;
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
                function release() {
                  if (released) return;
                  released = true;
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
