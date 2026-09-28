<?php

namespace App\Services\Shopify;

use App\Models\AuditIssue;
use App\Models\Optimization;
use App\Models\ShopInstallation;
use RuntimeException;

/**
 * Spec 4.12's medium tier ("Preview + explicit merchant approval") - unlike
 * safe-tier fixes, nothing here ever writes without a merchant first calling
 * preview() and then separately, explicitly, calling apply(). Two fix types
 * so far: minifying a stylesheet's own content, and deferring a
 * render-blocking <link> tag so it stops blocking render - other medium-risk
 * issue types stay recommendation-only until a fix worth automating exists
 * for them too.
 */
class MediumFixService
{
    public function __construct(
        private readonly ThemeAssetService $themeAssets,
        private readonly ThemeAssetLocatorService $locator,
        private readonly AssetBackupService $backups,
    ) {
    }

    /**
     * @return array{fix_type: string, asset_key: string, original_bytes: int, fixed_bytes: int, original_content: string, fixed_content: string}
     */
    public function preview(AuditIssue $issue, ShopInstallation $shop): array
    {
        $fixType = $issue->meta['fix_type'] ?? null;
        [$assetKey, $original, $fixed] = match ($fixType) {
            'minify_css' => $this->resolveMinify($issue, $shop),
            'defer_css' => $this->resolveDeferCss($issue, $shop),
            default => throw new RuntimeException('This issue has no medium-risk fix available.'),
        };

        return [
            'fix_type' => $fixType,
            'asset_key' => $assetKey,
            'original_bytes' => strlen($original),
            'fixed_bytes' => strlen($fixed),
            'original_content' => $original,
            'fixed_content' => $fixed,
        ];
    }

    public function apply(AuditIssue $issue, ShopInstallation $shop): Optimization
    {
        if (! $shop->target_theme_id) {
            throw new RuntimeException('No target theme selected - choose one on the Dashboard first.');
        }

        $fixType = $issue->meta['fix_type'] ?? null;
        [$assetKey, $original, $fixed] = match ($fixType) {
            'minify_css' => $this->resolveMinify($issue, $shop),
            'defer_css' => $this->resolveDeferCss($issue, $shop),
            default => throw new RuntimeException('This issue has no medium-risk fix available.'),
        };

        $writeThemeId = $shop->target_theme_id;

        $optimization = $shop->optimizations()->create([
            'audit_issue_id' => $issue->id,
            'type' => $fixType,
            'risk_tier' => 'medium',
            'status' => 'recommended',
            'theme_id' => $writeThemeId,
            'asset_key' => $assetKey,
        ]);

        $this->backups->backup($optimization, $writeThemeId, $assetKey, $original, $fixed);
        $this->themeAssets->write($writeThemeId, $assetKey, $fixed);

        $optimization->update(['status' => 'applied', 'applied_at' => now()]);

        return $optimization;
    }

    /**
     * @return array{0: string, 1: string, 2: string} [assetKey, originalContent, fixedContent]
     */
    private function resolveMinify(AuditIssue $issue, ShopInstallation $shop): array
    {
        if (($issue->meta['fix_type'] ?? null) !== 'minify_css') {
            throw new RuntimeException('This issue has no medium-risk fix available.');
        }

        $liveThemeId = $this->activeThemeIdOrFail($shop);

        $assetKey = $this->locator->findAssetByBasename($liveThemeId, $issue->meta['css_url'] ?? '');

        if (! $assetKey) {
            throw new RuntimeException('Could not locate this stylesheet as a real theme asset.');
        }

        $original = $this->themeAssets->read($liveThemeId, $assetKey);

        if ($original === null) {
            throw new RuntimeException('Could not read this stylesheet.');
        }

        return [$assetKey, $original, CssMinifier::minify($original)];
    }

    /**
     * Unlike minify_css, the fix here isn't the stylesheet's own content -
     * it's the <link> tag referencing it, wherever that tag actually lives
     * in the theme (a section, snippet, or layout file). findTagSource()
     * text-searches for that file the same way it already does for a
     * render-blocking <script src>.
     *
     * @return array{0: string, 1: string, 2: string} [assetKey, originalContent, fixedContent]
     */
    private function resolveDeferCss(AuditIssue $issue, ShopInstallation $shop): array
    {
        if (($issue->meta['fix_type'] ?? null) !== 'defer_css') {
            throw new RuntimeException('This issue has no medium-risk fix available.');
        }

        $cssUrl = $issue->meta['css_url'] ?? '';
        $liveThemeId = $this->activeThemeIdOrFail($shop);

        $assetKey = $this->locator->findTagSource($liveThemeId, $cssUrl);

        if (! $assetKey) {
            throw new RuntimeException('Could not find this stylesheet\'s <link> tag in your theme\'s files.');
        }

        $original = $this->themeAssets->read($liveThemeId, $assetKey);

        if ($original === null) {
            throw new RuntimeException('Could not read this theme file.');
        }

        $fixed = ThemeAssetLocatorService::deferStylesheetTag($original, $cssUrl);

        if ($fixed === $original) {
            throw new RuntimeException('Found the file but could not locate the exact <link> tag to change.');
        }

        return [$assetKey, $original, $fixed];
    }

    private function activeThemeIdOrFail(ShopInstallation $shop): string
    {
        // Read from the live theme - that's what was actually scanned - even
        // when writing lands on a preview duplicate instead.
        $liveThemeId = $this->themeAssets->activeThemeId();

        if (! $liveThemeId) {
            throw new RuntimeException('Could not access your theme right now.');
        }

        return $liveThemeId;
    }
}
