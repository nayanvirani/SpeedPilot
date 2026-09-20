<?php

namespace App\Jobs;

use App\Models\Audit;
use App\Models\ShopInstallation;
use App\Services\PlanPolicy;
use App\Services\Shopify\AssetBackupService;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\ThemeAssetService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 2: only "safe"-tier issues are applied automatically. Every write goes
 * through AssetBackupService first, so rollback is always a single action.
 * Medium/high risk issues are left as recommendation-only (routed through the
 * preview duplicate theme elsewhere), never touched here.
 */
class ApplySafeFixesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $shopInstallationId,
        private readonly int $auditId,
    ) {
    }

    public function handle(AssetBackupService $backups): void
    {
        $shop = ShopInstallation::findOrFail($this->shopInstallationId);
        $audit = Audit::findOrFail($this->auditId);
        $policy = new PlanPolicy($shop);

        $themeAssets = new ThemeAssetService(
            new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token)
        );

        $themeId = $themeAssets->activeThemeId();

        if (! $themeId) {
            return; // no OAuth/theme access yet - nothing to apply against
        }

        $safeIssues = $audit->issues()
            ->where('risk_tier', 'safe')
            ->where('fix_available', true)
            ->get()
            // A multi-page scan can report the same theme asset's issue
            // once per page it appears on (e.g. a shared header snippet) -
            // fixing it once already fixes it everywhere the asset is used,
            // so applying it again per duplicate would just waste the
            // plan's auto-fix limit on the same underlying change.
            ->unique(fn ($issue) => $issue->meta['asset_key'] ?? $issue->id);

        $limit = $policy->autoFixLimit();
        if ($limit !== null) {
            $safeIssues = $safeIssues->take($limit);
        }

        foreach ($safeIssues as $issue) {
            $assetKey = $issue->meta['asset_key'] ?? null;

            if (! $assetKey) {
                continue; // recommendation-only issue with no direct file target
            }

            $optimization = $shop->optimizations()->create([
                'audit_issue_id' => $issue->id,
                'type' => $issue->meta['fix_type'] ?? $issue->category,
                'risk_tier' => 'safe',
                'status' => 'recommended',
                'theme_id' => $themeId,
                'asset_key' => $assetKey,
            ]);

            $original = $themeAssets->read($themeId, $assetKey);

            if ($original === null) {
                continue;
            }

            $backups->backup($optimization, $themeId, $assetKey, $original);

            $fixed = $this->applyFix($issue->category, $issue->meta ?? [], $original);
            $themeAssets->write($themeId, $assetKey, $fixed);

            $optimization->update(['status' => 'applied', 'applied_at' => now()]);
        }
    }

    /**
     * Placeholder transform - the real fixers (image width/height injection,
     * lazy-load attribute, script defer) are Liquid/HTML string transforms
     * keyed by $category; wired here so ApplySafeFixesJob's flow (backup ->
     * write -> mark applied) doesn't change when they're filled in.
     */
    private function applyFix(string $category, array $meta, string $original): string
    {
        return match ($category) {
            'image' => $this->addImageDimensions($original, $meta),
            'js' => $this->deferScript($original, $meta),
            default => $original,
        };
    }

    private function addImageDimensions(string $content, array $meta): string
    {
        if (! isset($meta['selector'], $meta['width'], $meta['height'])) {
            return $content;
        }

        return preg_replace(
            '/(<img[^>]*'.preg_quote($meta['selector'], '/').'[^>]*?)>/i',
            '$1 width="'.(int) $meta['width'].'" height="'.(int) $meta['height'].'" loading="lazy">',
            $content,
            1,
        ) ?? $content;
    }

    private function deferScript(string $content, array $meta): string
    {
        if (! isset($meta['script_src'])) {
            return $content;
        }

        $src = preg_quote($meta['script_src'], '/');

        return preg_replace(
            '/<script([^>]*src=["\']'.$src.'["\'][^>]*)>/i',
            '<script$1 defer>',
            $content,
            1,
        ) ?? $content;
    }
}
