<?php

namespace App\Jobs;

use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\ShopInstallation;
use App\Services\PlanPolicy;
use App\Services\Shopify\AssetBackupService;
use App\Services\Shopify\ImageLazyLoadSweeper;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\ThemeAssetLocatorService;
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
        $locator = new ThemeAssetLocatorService($themeAssets);
        $sweeper = new ImageLazyLoadSweeper($themeAssets);

        $themeId = $themeAssets->activeThemeId();

        if (! $themeId) {
            return; // no OAuth/theme access yet - nothing to apply against
        }

        $safeIssues = $audit->issues()
            ->where('risk_tier', 'safe')
            ->where('fix_available', true)
            ->get()
            // A multi-page scan can report the same underlying fix once per
            // page it appears on (e.g. a shared header script, or an image
            // lazy-load sweep that isn't tied to one specific image at all)
            // - applying it again per duplicate would just waste the plan's
            // auto-fix limit on the same underlying change.
            ->unique(fn (AuditIssue $issue) => $issue->meta['asset_key']
                ?? $issue->meta['fix_type']
                ?? $issue->id);

        $limit = $policy->autoFixLimit();
        if ($limit !== null) {
            $safeIssues = $safeIssues->take($limit);
        }

        foreach ($safeIssues as $issue) {
            $fixType = $issue->meta['fix_type'] ?? null;

            if ($fixType === 'lazy_load_sweep') {
                $this->applyLazyLoadSweep($shop, $issue, $themeId, $themeAssets, $backups, $sweeper);

                continue;
            }

            $assetKey = $issue->meta['asset_key'] ?? null;

            if (! $assetKey && $fixType === 'defer_script' && isset($issue->meta['script_src'])) {
                $assetKey = $locator->findScriptSource($themeId, $issue->meta['script_src']);
            }

            if (! $assetKey) {
                continue; // couldn't resolve a real file to edit - recommendation-only
            }

            $optimization = $shop->optimizations()->create([
                'audit_issue_id' => $issue->id,
                'type' => $fixType ?? $issue->category,
                'risk_tier' => 'safe',
                'status' => 'recommended',
                'theme_id' => $themeId,
                'asset_key' => $assetKey,
            ]);

            $original = $themeAssets->read($themeId, $assetKey);

            if ($original === null) {
                continue;
            }

            $fixed = $this->applyFix($issue->category, $issue->meta ?? [], $original);

            $backups->backup($optimization, $themeId, $assetKey, $original, $fixed);
            $themeAssets->write($themeId, $assetKey, $fixed);

            $optimization->update(['status' => 'applied', 'applied_at' => now()]);
        }
    }

    /**
     * Not tied to one specific image - loading="lazy" is safe to add to every
     * plain <img> across the theme's sections/snippets, so this applies
     * everywhere at once. One Optimization row per file actually changed,
     * so each stays independently backed-up and rollback-able.
     */
    private function applyLazyLoadSweep(
        ShopInstallation $shop,
        AuditIssue $issue,
        string $themeId,
        ThemeAssetService $themeAssets,
        AssetBackupService $backups,
        ImageLazyLoadSweeper $sweeper,
    ): void {
        foreach ($sweeper->sweep($themeId) as $filename => $change) {
            $optimization = $shop->optimizations()->create([
                'audit_issue_id' => $issue->id,
                'type' => 'lazy_load',
                'risk_tier' => 'safe',
                'status' => 'recommended',
                'theme_id' => $themeId,
                'asset_key' => $filename,
            ]);

            $backups->backup($optimization, $themeId, $filename, $change['original'], $change['updated']);
            $themeAssets->write($themeId, $filename, $change['updated']);
            $optimization->update(['status' => 'applied', 'applied_at' => now()]);
        }
    }

    private function applyFix(string $category, array $meta, string $original): string
    {
        return match ($category) {
            'js' => $this->deferScript($original, $meta),
            default => $original,
        };
    }

    private function deferScript(string $content, array $meta): string
    {
        if (! isset($meta['script_src'])) {
            return $content;
        }

        // The theme's source may reference this script via a relative path,
        // an asset_url filter, or a version-stamped query string that won't
        // match the fully rendered URL Lighthouse reported - matching on the
        // file's distinctive basename (the same needle used to locate this
        // file in the first place) is what actually works reliably here.
        $needle = preg_quote(basename((string) parse_url($meta['script_src'], PHP_URL_PATH)), '/');

        return preg_replace(
            '/<script([^>]*src=["\'][^"\']*'.$needle.'[^"\']*["\'][^>]*)>/i',
            '<script$1 defer>',
            $content,
            1,
        ) ?? $content;
    }
}
