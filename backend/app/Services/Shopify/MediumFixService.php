<?php

namespace App\Services\Shopify;

use App\Models\AuditIssue;
use App\Models\Optimization;
use App\Models\ShopInstallation;
use RuntimeException;

/**
 * Spec 4.12's medium tier ("Preview + explicit merchant approval") - unlike
 * safe-tier fixes, nothing here ever writes without a merchant first calling
 * preview() and then separately, explicitly, calling apply(). Currently
 * covers CSS minification only; other medium-risk issue types stay
 * recommendation-only until a fix worth automating exists for them too.
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
     * @return array{asset_key: string, original_bytes: int, minified_bytes: int, savings_bytes: int, original_content: string, minified_content: string}
     */
    public function preview(AuditIssue $issue, ShopInstallation $shop): array
    {
        [$assetKey, $original] = $this->resolve($issue, $shop);
        $minified = CssMinifier::minify($original);

        return [
            'asset_key' => $assetKey,
            'original_bytes' => strlen($original),
            'minified_bytes' => strlen($minified),
            'savings_bytes' => strlen($original) - strlen($minified),
            'original_content' => $original,
            'minified_content' => $minified,
        ];
    }

    public function apply(AuditIssue $issue, ShopInstallation $shop): Optimization
    {
        if (! $shop->target_theme_id) {
            throw new RuntimeException('No target theme selected - choose one on the Dashboard first.');
        }

        [$assetKey, $original] = $this->resolve($issue, $shop);
        $minified = CssMinifier::minify($original);
        $writeThemeId = $shop->target_theme_id;

        $optimization = $shop->optimizations()->create([
            'audit_issue_id' => $issue->id,
            'type' => 'minify_css',
            'risk_tier' => 'medium',
            'status' => 'recommended',
            'theme_id' => $writeThemeId,
            'asset_key' => $assetKey,
        ]);

        $this->backups->backup($optimization, $writeThemeId, $assetKey, $original, $minified);
        $this->themeAssets->write($writeThemeId, $assetKey, $minified);

        $optimization->update(['status' => 'applied', 'applied_at' => now()]);

        return $optimization;
    }

    /**
     * @return array{0: string, 1: string} [assetKey, originalContent]
     */
    private function resolve(AuditIssue $issue, ShopInstallation $shop): array
    {
        if (($issue->meta['fix_type'] ?? null) !== 'minify_css') {
            throw new RuntimeException('This issue has no medium-risk fix available.');
        }

        // Read from the live theme - that's what was actually scanned - even
        // when writing lands on a preview duplicate instead.
        $liveThemeId = $this->themeAssets->activeThemeId();

        if (! $liveThemeId) {
            throw new RuntimeException('Could not access your theme right now.');
        }

        $assetKey = $this->locator->findAssetByBasename($liveThemeId, $issue->meta['css_url'] ?? '');

        if (! $assetKey) {
            throw new RuntimeException('Could not locate this stylesheet as a real theme asset.');
        }

        $original = $this->themeAssets->read($liveThemeId, $assetKey);

        if ($original === null) {
            throw new RuntimeException('Could not read this stylesheet.');
        }

        return [$assetKey, $original];
    }
}
