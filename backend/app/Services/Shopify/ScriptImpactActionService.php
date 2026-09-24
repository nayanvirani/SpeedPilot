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
     *
     * The engine itself is NOT written into the merchant's theme - only a
     * single, stable <script src> tag pointing at the public
     * /storefront/interceptor.js endpoint (InterceptorController), which
     * generates the actual watcher JS (engine + this shop's current delay
     * list) fresh on every request. That keeps the real implementation off
     * every merchant's theme editor, means toggling a delay on/off never
     * needs another theme write (only the DB row changes - the endpoint
     * just reads it live), and - the actual point of hosting it, not
     * secrecy for its own sake - ties the feature to an active
     * install/subscription: the endpoint checks $shop->isActive() before
     * returning anything, so uninstalling stops it immediately with no
     * separate cleanup step. None of this makes the JS un-inspectable to
     * someone who opens browser DevTools - nothing that runs in a browser
     * ever is - it just isn't sitting in cleartext in the theme source.
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

        $target = $shop->interceptorDelayTargets()->firstOrCreate(['script_url' => $impact->script_url]);
        $result = $this->ensureInterceptorTagInstalled($shop);

        // If the tag was never actually installed (this shop's very first
        // attempt, and it failed/is blocked), the engine never loads on the
        // storefront at all - don't leave a target that would show
        // "Delayed (experimental)" in the UI for something that isn't
        // happening. Once the tag exists, every later call here is a no-op
        // success regardless of which app it's for, so this only ever fires
        // on a shop's genuinely first, failed attempt.
        if (! $result['applied'] && $target->wasRecentlyCreated) {
            $target->delete();
        }

        return $result;
    }

    /**
     * Just removes this shop's target - the shared theme.liquid tag stays
     * (harmless, and the hosted endpoint naturally starts returning a no-op
     * script once a shop has no targets left, so there's nothing to revert
     * in the theme itself - see the class docblock above).
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
     * Read-only twin of interceptorDelay() - previews installing the tag
     * (a merchant without theme-write access approved yet can paste this
     * themselves), same contract as preview() above. Shows no diff if the
     * tag is already installed - nothing left to preview at that point.
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

        try {
            $updated = self::insertInstallBlock($original, $shop->interceptorToken());
        } catch (\RuntimeException $e) {
            return [...$empty, 'asset_key' => $assetKey, 'error' => $e->getMessage()];
        }

        return ['asset_key' => $assetKey, 'original' => $original, 'fixed' => $updated, 'error' => null];
    }

    /**
     * Idempotent - if the tag is already there, this is a no-op success and
     * never touches the theme again. Only the very first "Advanced delay"
     * click for a shop ever writes theme.liquid; every toggle after that
     * (for this app or any other) is purely a DB change the hosted endpoint
     * picks up on its own next request.
     *
     * @return array{applied: bool, message: ?string, blocked: bool}
     */
    private function ensureInterceptorTagInstalled(ShopInstallation $shop): array
    {
        $themeId = $shop->target_theme_id;
        $assetKey = 'layout/theme.liquid';

        $original = $this->themeAssets->read($themeId, $assetKey);

        if ($original === null) {
            return ['applied' => false, 'message' => 'Could not read your theme.liquid file.', 'blocked' => false];
        }

        if (str_contains($original, self::MARKER_START)) {
            return ['applied' => true, 'message' => null, 'blocked' => false];
        }

        try {
            $updated = self::insertInstallBlock($original, $shop->interceptorToken());
        } catch (\RuntimeException $e) {
            return ['applied' => false, 'message' => $e->getMessage(), 'blocked' => false];
        }

        // Reuse a still-pending attempt from an earlier blocked try instead
        // of creating a new one every time - without this, retrying while
        // theme-write access isn't approved yet (a normal, possibly
        // repeated state, not a one-off) piled up a fresh "recommended,
        // never applied" row on the Optimizations page on every click.
        $optimization = $shop->optimizations()
            ->where('type', 'interceptor_install')
            ->where('status', 'recommended')
            ->first();

        if (! $optimization) {
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
        $optimization->update(['status' => 'applied', 'applied_at' => now()]);

        return ['applied' => true, 'message' => null, 'blocked' => false];
    }

    public static function insertInstallBlock(string $liquidContent, string $token): string
    {
        if (str_contains($liquidContent, self::MARKER_START)) {
            return $liquidContent;
        }

        // Deliberately NOT async/defer, despite the real cost: this tag has
        // to finish fetching and running - registering the MutationObserver
        // - before the browser parser reaches whatever inserted content_for
        // _header injects, or there's nothing to catch by the time it's
        // listening. Verified live against a real site with async: every
        // watched resource fired 2-4s after page load, none of it delayed -
        // the trackers' own bootstrap scripts were consistently winning the
        // race to load and insert their dynamic sub-resources before our
        // script had even finished fetching from our own server. Blocking
        // here is a real, honest tradeoff (a small network round-trip added
        // to the critical path) in exchange for the feature doing anything
        // at all - an async tag that catches nothing is a worse trade.
        $url = rtrim(config('app.url'), '/').'/storefront/interceptor.js?t='.urlencode($token);
        // A Service Worker can only ever cover a visitor's 2nd navigation
        // onward (it can't control the page that first registers it), so
        // this runs alongside the MutationObserver engine above, not
        // instead of it - registered against the App Proxy path so it's
        // same-origin with the storefront, a hard browser requirement our
        // own Railway domain can't satisfy directly. See AppProxyController
        // and ScriptImpactActionService::serviceWorkerJs().
        $swRegistration = "if ('serviceWorker' in navigator) { navigator.serviceWorker.register('/apps/speedpilot/sw.js'); }";
        $block = self::MARKER_START
            ."\n<script src=\"".e($url)."\" fetchpriority=\"high\"></script>"
            ."\n<script>{$swRegistration}</script>\n"
            .self::MARKER_END;

        foreach (['{{- content_for_header -}}', '{{ content_for_header }}', '{{content_for_header}}'] as $needle) {
            if (str_contains($liquidContent, $needle)) {
                return str_replace($needle, $block."\n".$needle, $liquidContent);
            }
        }

        throw new \RuntimeException("Couldn't find a safe place to add this to your theme.liquid - contact SpeedPilot support.");
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
     * A stronger, second layer above interceptorEngineJs(): that engine can
     * only ever catch a resource genuinely inserted by other JS after page
     * load - it was verified live to catch nothing for scripts Shopify
     * delivers via content_for_header, since that's server-rendered HTML,
     * not a JS-driven insertion, and no page-level JS (ours or anyone
     * else's) gets a chance to intervene before the browser's parser has
     * already dispatched those fetches.
     *
     * A Service Worker can intercept the page's own HTML *document*
     * response and rewrite it before the browser ever parses it - not
     * reacting after the fact, changing what the parser sees in the first
     * place. Only takes over from a visitor's 2nd navigation onward (a
     * Service Worker structurally cannot control the page that first
     * registers it) - interceptorEngineJs() keeps running unconditionally
     * alongside this for first-navigation coverage.
     *
     * Safety is the entire point here, more than in any other fix this app
     * makes: a bug in this rewrite can break the merchant's *entire page*
     * for every visitor, not just fail to delay something. Every failure
     * path below falls back to passing the real response through
     * completely untouched.
     *
     * $patternsUrl is baked in at generation time (same pattern as
     * interceptorEngineJs()'s $urls) - the SW re-fetches it periodically
     * (SP_STATE_TTL) rather than trusting a single snapshot for its whole
     * lifetime, so toggling delay targets or the kill switch takes effect
     * within seconds, not only on the next SW update.
     */
    public static function serviceWorkerJs(string $patternsUrl): string
    {
        $json = json_encode($patternsUrl, JSON_UNESCAPED_SLASHES);

        return <<<JS
            'use strict';

            var SP_PATTERNS_URL = {$json};
            var SP_STATE = { patterns: [], enabled: false, fetchedAt: 0 };
            var SP_STATE_TTL = 30000;

            self.addEventListener('install', function () {
              self.skipWaiting();
            });

            self.addEventListener('activate', function (event) {
              event.waitUntil(self.clients.claim());
            });

            function spRefreshState() {
              var now = Date.now();
              if (now - SP_STATE.fetchedAt < SP_STATE_TTL) {
                return Promise.resolve(SP_STATE);
              }
              return fetch(SP_PATTERNS_URL, { cache: 'no-store' })
                .then(function (res) { return res.ok ? res.json() : null; })
                .then(function (data) {
                  if (data) {
                    SP_STATE = { patterns: data.patterns || [], enabled: !!data.enabled, fetchedAt: now };
                  }
                  return SP_STATE;
                })
                .catch(function () {
                  // A hiccup fetching our own config must never block a real
                  // page navigation - proceed with whatever was last known.
                  return SP_STATE;
                });
            }

            function spMatches(url, patterns) {
              return !!url && patterns.some(function (p) { return url.indexOf(p) !== -1; });
            }

            function spRewriteText(text, patterns) {
              return text.replace(/<script\\b([^>]*?)\\ssrc=(["'])([^"']*)\\2([^>]*)>/gi, function (whole, before, quote, url, after) {
                if (!spMatches(url, patterns)) return whole;
                return '<script' + before + ' data-speedpilot-delayed="1" data-speedpilot-src=' + quote + url + quote + after + '>';
              });
            }

            var SP_RELEASE_SCRIPT = '<script>(function(){function r(){' +
              'document.querySelectorAll("[data-speedpilot-delayed]").forEach(function(n){' +
              'n.src=n.getAttribute("data-speedpilot-src");n.removeAttribute("data-speedpilot-delayed");' +
              'n.removeAttribute("data-speedpilot-src");});}' +
              '["mousemove","scroll","touchstart","keydown"].forEach(function(e){window.addEventListener(e,r,{once:true,passive:true});});' +
              'setTimeout(r,5000);})();</script>';

            function spRewriteResponse(response, patterns) {
              var reader = response.body.getReader();
              var decoder = new TextDecoder('utf-8');
              var encoder = new TextEncoder();
              var buffered = '';
              var KEEP_TAIL = 2048;

              var stream = new ReadableStream({
                start: function (controller) {
                  function pump() {
                    return reader.read().then(function (result) {
                      if (result.done) {
                        var tail = buffered + decoder.decode();
                        var bodyIdx = tail.lastIndexOf('</body>');
                        tail = bodyIdx !== -1
                          ? tail.slice(0, bodyIdx) + SP_RELEASE_SCRIPT + tail.slice(bodyIdx)
                          : tail + SP_RELEASE_SCRIPT;
                        controller.enqueue(encoder.encode(spRewriteText(tail, patterns)));
                        controller.close();
                        return;
                      }
                      buffered += decoder.decode(result.value, { stream: true });
                      if (buffered.length > KEEP_TAIL * 2) {
                        var flushLen = buffered.length - KEEP_TAIL;
                        var toFlush = buffered.slice(0, flushLen);
                        buffered = buffered.slice(flushLen);
                        controller.enqueue(encoder.encode(spRewriteText(toFlush, patterns)));
                      }
                      return pump();
                    });
                  }
                  return pump();
                },
              });

              var headers = new Headers(response.headers);
              headers.delete('content-length');

              return new Response(stream, { status: response.status, statusText: response.statusText, headers: headers });
            }

            self.addEventListener('fetch', function (event) {
              if (event.request.mode !== 'navigate' || event.request.method !== 'GET') {
                return;
              }

              event.respondWith(
                spRefreshState()
                  .then(function (state) {
                    return fetch(event.request).then(function (response) {
                      if (!state.enabled || !state.patterns.length) {
                        return response;
                      }
                      var contentType = response.headers.get('content-type') || '';
                      if (contentType.indexOf('text/html') === -1) {
                        return response;
                      }
                      try {
                        return spRewriteResponse(response, state.patterns);
                      } catch (e) {
                        return response;
                      }
                    });
                  })
                  .catch(function () {
                    return fetch(event.request);
                  })
              );
            });
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
