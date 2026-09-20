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
        return $this->applyEdit($shop, $impact, 'disabled', function (string $content, string $needle) {
            $pattern = '/<script\b[^>]*src=["\'][^"\']*'.$needle.'[^"\']*["\'][^>]*>.*?<\/script>/is';

            return preg_replace($pattern, '', $content, 1) ?? $content;
        });
    }

    /**
     * @return array{applied: bool, message: ?string}
     */
    public function delay(ShopInstallation $shop, AppImpact $impact): array
    {
        return $this->applyEdit($shop, $impact, 'delayed', function (string $content, string $needle) {
            $pattern = '/<script\b[^>]*\bsrc=["\']([^"\']*'.$needle.'[^"\']*)["\'][^>]*>.*?<\/script>/is';

            return preg_replace_callback($pattern, function (array $m) {
                return $this->delayedLoaderSnippet($m[1]);
            }, $content, 1) ?? $content;
        });
    }

    /**
     * @return array{applied: bool, message: ?string}
     */
    public function restore(ShopInstallation $shop, AppImpact $impact): array
    {
        $optimization = $shop->optimizations()
            ->where('app_impact_id', $impact->id)
            ->where('status', 'applied')
            ->latest()
            ->first();

        if (! $optimization) {
            // Nothing was ever actually applied (e.g. coming back from
            // "excluded", which never touches the theme) - there's nothing
            // to restore, and that's fine.
            return ['applied' => true, 'message' => null];
        }

        $restored = $this->backups->restore($optimization, $this->themeAssets);

        return [
            'applied' => $restored,
            'message' => $restored ? null : 'Could not restore the original script automatically.',
        ];
    }

    /**
     * @param  callable(string, string): string  $transform
     * @return array{applied: bool, message: ?string}
     */
    private function applyEdit(ShopInstallation $shop, AppImpact $impact, string $action, callable $transform): array
    {
        if (! $impact->script_url) {
            return ['applied' => false, 'message' => "No script URL was recorded for this app, so it can't be located automatically."];
        }

        $themeId = $this->themeAssets->activeThemeId();

        if (! $themeId) {
            return ['applied' => false, 'message' => 'Could not access your theme right now - try again shortly.'];
        }

        $assetKey = $this->locator->findScriptSource($themeId, $impact->script_url);

        if (! $assetKey) {
            return [
                'applied' => false,
                'message' => "Couldn't find this script directly in your theme's files - it's most likely "
                    ."injected by the app itself (e.g. via Shopify's Script Tag API), which SpeedPilot ".
                    "can't edit. Try disabling the app from your Shopify admin, or contact the app's support.",
            ];
        }

        $original = $this->themeAssets->read($themeId, $assetKey);

        if ($original === null) {
            return ['applied' => false, 'message' => 'Could not read the theme file.'];
        }

        // Must match the needle findScriptSource() used to locate $assetKey
        // in the first place - a different needle here risks "found the
        // file but not the tag inside it."
        $needle = preg_quote(ThemeAssetLocatorService::needleFor($impact->script_url), '/');
        $updated = $transform($original, $needle);

        if ($updated === $original) {
            return ['applied' => false, 'message' => "Found the file but couldn't locate the exact script tag inside it."];
        }

        $optimization = $shop->optimizations()->create([
            'app_impact_id' => $impact->id,
            'type' => $action,
            // Unlike the audit-issue safe fixes, this removes or delays
            // another app's own functionality on the storefront (not just
            // a performance attribute) - always at least medium risk.
            'risk_tier' => 'medium',
            'status' => 'recommended',
            'theme_id' => $themeId,
            'asset_key' => $assetKey,
        ]);

        $this->backups->backup($optimization, $themeId, $assetKey, $original, $updated);
        $this->themeAssets->write($themeId, $assetKey, $updated);
        $optimization->update(['status' => 'applied', 'applied_at' => now()]);

        return ['applied' => true, 'message' => null];
    }

    private function delayedLoaderSnippet(string $src): string
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
