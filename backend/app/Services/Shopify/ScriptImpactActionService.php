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

    private const MARKER_START = '<!-- SpeedPilot:interceptor:start -->';
    private const MARKER_END = '<!-- SpeedPilot:interceptor:end -->';

    /**
     * The "Advanced delay (experimental)" action - for a script SpeedPilot
     * can't find in theme files at all (Shopify ScriptTag-injected), this
     * doesn't edit the app's own tag (impossible - it isn't theme content).
     * Instead it maintains one shared interceptor snippet in theme.liquid
     * that watches for this script's URL being inserted into the DOM and
     * delays it - best-effort, not guaranteed, see renderInterceptorBlock().
     *
     * @return array{applied: bool, message: ?string, blocked: bool}
     */
    public function interceptorDelay(ShopInstallation $shop, AppImpact $impact): array
    {
        if (! $impact->script_url) {
            return ['applied' => false, 'message' => "No script URL was recorded for this app, so it can't be targeted.", 'blocked' => false];
        }

        if (! $shop->target_theme_id) {
            return ['applied' => false, 'message' => 'Pick which theme SpeedPilot should apply changes to first (Dashboard > Target theme).', 'blocked' => false];
        }

        $shop->interceptorDelayTargets()->firstOrCreate(['script_url' => $impact->script_url]);

        return $this->rewriteInterceptorBlock($shop);
    }

    /**
     * @return array{applied: bool, message: ?string, blocked: bool}
     */
    public function restoreInterceptor(ShopInstallation $shop, AppImpact $impact): array
    {
        if ($impact->script_url) {
            $shop->interceptorDelayTargets()->where('script_url', $impact->script_url)->delete();
        }

        return $this->rewriteInterceptorBlock($shop);
    }

    /**
     * Read-only twin of interceptorDelay() - previews the theme.liquid diff
     * without writing it, same contract as preview() above.
     *
     * @return array{asset_key: ?string, original: ?string, fixed: ?string, error: ?string}
     */
    public function previewInterceptor(ShopInstallation $shop, AppImpact $impact): array
    {
        $empty = ['asset_key' => null, 'original' => null, 'fixed' => null, 'error' => null];

        if (! $impact->script_url) {
            return [...$empty, 'error' => "No script URL was recorded for this app, so it can't be targeted."];
        }

        if (! $shop->target_theme_id) {
            return [...$empty, 'error' => 'Pick which theme SpeedPilot should apply changes to first (Dashboard > Target theme).'];
        }

        $assetKey = 'layout/theme.liquid';
        $original = $this->themeAssets->read($shop->target_theme_id, $assetKey);

        if ($original === null) {
            return [...$empty, 'asset_key' => $assetKey, 'error' => 'Could not read your theme.liquid file.'];
        }

        $urls = $shop->interceptorDelayTargets()->pluck('script_url')->push($impact->script_url)->unique()->values()->all();

        try {
            $updated = self::renderInterceptorBlock($original, $urls);
        } catch (\RuntimeException $e) {
            return [...$empty, 'asset_key' => $assetKey, 'error' => $e->getMessage()];
        }

        return ['asset_key' => $assetKey, 'original' => $original, 'fixed' => $updated, 'error' => null];
    }

    /**
     * Regenerates the interceptor block from the shop's *entire current*
     * interceptor_delay_targets list and writes it - never patches just one
     * entry. Multiple app_impacts share this one theme.liquid block, so
     * "what should it contain right now" is always derived fresh from the
     * DB rather than trying to revert a specific prior diff (which would be
     * order-dependent across independent toggles of different apps).
     *
     * @return array{applied: bool, message: ?string, blocked: bool}
     */
    private function rewriteInterceptorBlock(ShopInstallation $shop): array
    {
        $themeId = $shop->target_theme_id;
        $assetKey = 'layout/theme.liquid';

        $original = $this->themeAssets->read($themeId, $assetKey);

        if ($original === null) {
            return ['applied' => false, 'message' => 'Could not read your theme.liquid file.', 'blocked' => false];
        }

        $urls = $shop->interceptorDelayTargets()->pluck('script_url')->all();

        try {
            $updated = self::renderInterceptorBlock($original, $urls);
        } catch (\RuntimeException $e) {
            return ['applied' => false, 'message' => $e->getMessage(), 'blocked' => false];
        }

        if ($updated === $original) {
            return ['applied' => true, 'message' => null, 'blocked' => false];
        }

        // Only back up on the very first-ever interceptor write (when the
        // marker doesn't exist yet) - this preserves the pristine original
        // for a full manual rollback later. Routine toggles after that just
        // regenerate the block; there's no single "prior state" to restore
        // to that would be correct for every other app sharing this file.
        $optimization = null;

        if (! str_contains($original, self::MARKER_START)) {
            $optimization = $shop->optimizations()->create([
                'type' => 'interceptor_install',
                'risk_tier' => 'medium',
                'status' => 'recommended',
                'theme_id' => $themeId,
                'asset_key' => $assetKey,
            ]);

            $this->backups->backup($optimization, $themeId, $assetKey, $original, $updated);
        }

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
        $optimization?->update(['status' => 'applied', 'applied_at' => now()]);

        return ['applied' => true, 'message' => null, 'blocked' => false];
    }

    /**
     * @param  array<int, string>  $urls
     */
    public static function renderInterceptorBlock(string $liquidContent, array $urls): string
    {
        $urls = array_values(array_unique($urls));

        if (empty($urls)) {
            // Nothing left to delay - strip the block entirely rather than
            // leaving a dead, empty watcher in the merchant's theme.
            $pattern = '/\s*'.preg_quote(self::MARKER_START, '/').'.*?'.preg_quote(self::MARKER_END, '/').'/s';

            return preg_replace($pattern, '', $liquidContent) ?? $liquidContent;
        }

        $block = self::MARKER_START."\n".self::interceptorSnippet($urls)."\n".self::MARKER_END;

        if (str_contains($liquidContent, self::MARKER_START) && str_contains($liquidContent, self::MARKER_END)) {
            $pattern = '/'.preg_quote(self::MARKER_START, '/').'.*?'.preg_quote(self::MARKER_END, '/').'/s';

            return preg_replace($pattern, $block, $liquidContent, 1) ?? $liquidContent;
        }

        foreach (['{{- content_for_header -}}', '{{ content_for_header }}', '{{content_for_header}}'] as $needle) {
            if (str_contains($liquidContent, $needle)) {
                return str_replace($needle, $block."\n".$needle, $liquidContent);
            }
        }

        throw new \RuntimeException("Couldn't find a safe place to add this to your theme.liquid - contact SpeedPilot support.");
    }

    /**
     * Best-effort, not guaranteed: catches scripts/stylesheets *dynamically
     * inserted* into the DOM by other JS after page load (how most heavy
     * third-party trackers actually load their real payload) via
     * MutationObserver, and delays them the same way delayedLoaderSnippet()
     * above does - same trigger set, for a consistent merchant-facing
     * experience regardless of which mechanism ends up handling a given
     * script. Cannot intercept a script that's static markup in the initial
     * HTML response (Shopify's own ScriptTag rendering) - the browser
     * dispatches that fetch as it parses the tag, before any JS callback
     * (including this one) gets a chance to run.
     *
     * @param  array<int, string>  $urls
     */
    private static function interceptorSnippet(array $urls): string
    {
        $json = json_encode(array_values($urls));

        return <<<HTML
            <script>
            (function () {
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
              ['mousemove', 'scroll', 'touchstart', 'keydown'].forEach(function (evt) {
                window.addEventListener(evt, release, { once: true, passive: true });
              });
              setTimeout(release, 5000);
              new MutationObserver(function (mutations) {
                if (released) return;
                mutations.forEach(function (m) {
                  (m.addedNodes || []).forEach(function (node) {
                    var isScript = node.tagName === 'SCRIPT' && matches(node.src);
                    var isStyle = node.tagName === 'LINK' && node.rel === 'stylesheet' && matches(node.href);
                    if (isScript || isStyle) {
                      node.remove();
                      pending.push(node);
                    }
                  });
                });
              }).observe(document.documentElement, { childList: true, subtree: true });
            })();
            </script>
            HTML;
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
