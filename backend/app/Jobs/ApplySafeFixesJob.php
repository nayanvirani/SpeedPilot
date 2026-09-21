<?php

namespace App\Jobs;

use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\ShopInstallation;
use App\Services\PlanPolicy;
use App\Services\Shopify\AssetBackupService;
use App\Services\Shopify\ImageLazyLoadSweeper;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Models\OptimizedTheme;
use App\Services\Shopify\ThemeAssetLocatorService;
use App\Services\Shopify\ThemeAssetService;
use App\Services\Shopify\ThemeDuplicateService;
use App\Services\Shopify\ThemeWriteAccessDeniedException;
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

        // Nowhere to write until the merchant explicitly picks a target
        // theme (their live theme, or a SpeedPilot-managed preview
        // duplicate) - issues stay recommendation-only rather than
        // defaulting to silently editing whatever Shopify reports as live.
        if (! $shop->target_theme_id) {
            return;
        }

        $themeAssets = new ThemeAssetService(
            new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token)
        );
        $locator = new ThemeAssetLocatorService($themeAssets);
        $sweeper = new ImageLazyLoadSweeper($themeAssets);

        // Issues were found by scanning the live, rendered storefront, so
        // locating/reading the flagged files has to happen against the live
        // theme regardless of where the fix is written - a fresh preview
        // duplicate starts identical to it anyway.
        $liveThemeId = $themeAssets->activeThemeId();
        $writeThemeId = $shop->target_theme_id;

        if (! $liveThemeId) {
            return; // no OAuth/theme access yet - nothing to apply against
        }

        // About to write fresh fixes into the preview duplicate - re-baseline
        // its divergence checksum against the live theme now, so a settings-
        // page check later reports drift accumulated *after* this write, not
        // drift this write is about to fold in anyway.
        if ($shop->target_theme_mode === 'duplicate') {
            $optimizedTheme = OptimizedTheme::where('shop_installation_id', $shop->id)
                ->where('duplicate_theme_id', $writeThemeId)
                ->first();

            if ($optimizedTheme) {
                $duplicator = new ThemeDuplicateService(new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token));
                $duplicator->checkDivergence($optimizedTheme, persist: true);
            }
        }

        $appliedCount = 0;

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
            try {
                $fixType = $issue->meta['fix_type'] ?? null;

                if ($fixType === 'lazy_load_sweep') {
                    $appliedCount += $this->applyLazyLoadSweep($shop, $issue, $liveThemeId, $writeThemeId, $themeAssets, $backups, $sweeper);

                    continue;
                }

                $assetKey = $issue->meta['asset_key'] ?? null;

                if (! $assetKey && $fixType === 'defer_script' && isset($issue->meta['script_src'])) {
                    $assetKey = $locator->findScriptSource($liveThemeId, $issue->meta['script_src']);
                }

                if (! $assetKey) {
                    continue; // couldn't resolve a real file to edit - recommendation-only
                }

                $optimization = $shop->optimizations()->create([
                    'audit_issue_id' => $issue->id,
                    'type' => $fixType ?? $issue->category,
                    'risk_tier' => 'safe',
                    'status' => 'recommended',
                    'theme_id' => $writeThemeId,
                    'asset_key' => $assetKey,
                ]);

                $original = $themeAssets->read($liveThemeId, $assetKey);

                if ($original === null) {
                    continue;
                }

                $fixed = $this->applyFix($issue->category, $issue->meta ?? [], $original);

                $backups->backup($optimization, $writeThemeId, $assetKey, $original, $fixed);
                $themeAssets->write($writeThemeId, $assetKey, $fixed);

                $optimization->update(['status' => 'applied', 'applied_at' => now()]);
                $appliedCount++;
            } catch (ThemeWriteAccessDeniedException) {
                // Shopify hasn't approved this app's write_themes exemption
                // yet - an account-wide gate, so every remaining write in
                // this batch would fail identically. Stop rather than churn
                // through the rest, and flag it for the Dashboard to say so
                // honestly instead of quietly reporting "0 fixes applied."
                $shop->update(['theme_write_blocked_at' => now()]);

                break;
            }
        }

        // Safety rule: validate/re-scan after applying changes. Comparable
        // to the original only when the fix actually landed on the same
        // thing being scanned - a live-theme fix gets a normal full re-scan,
        // but a preview-duplicate fix needs Shopify's theme preview URL, or
        // "verify" would just be re-measuring the untouched live site.
        if ($appliedCount > 0) {
            $shop->update(['theme_write_blocked_at' => null]);

            $verifyUrl = $shop->target_theme_mode === 'duplicate'
                ? "https://{$shop->shop_domain}/?preview_theme_id={$writeThemeId}"
                : null;

            $verificationAudit = $shop->audits()->create([
                'verifies_audit_id' => $audit->id,
                'url' => $verifyUrl,
                'status' => 'pending',
            ]);

            RunAuditJob::dispatch($verificationAudit->id);
        }
    }

    /**
     * Not tied to one specific image - loading="lazy" is safe to add to every
     * plain <img> across the theme's sections/snippets, so this applies
     * everywhere at once. One Optimization row per file actually changed,
     * so each stays independently backed-up and rollback-able.
     *
     * @return int number of files actually changed
     */
    private function applyLazyLoadSweep(
        ShopInstallation $shop,
        AuditIssue $issue,
        string $liveThemeId,
        string $writeThemeId,
        ThemeAssetService $themeAssets,
        AssetBackupService $backups,
        ImageLazyLoadSweeper $sweeper,
    ): int {
        $changedCount = 0;

        foreach ($sweeper->sweep($liveThemeId) as $filename => $change) {
            $optimization = $shop->optimizations()->create([
                'audit_issue_id' => $issue->id,
                'type' => 'lazy_load',
                'risk_tier' => 'safe',
                'status' => 'recommended',
                'theme_id' => $writeThemeId,
                'asset_key' => $filename,
            ]);

            $backups->backup($optimization, $writeThemeId, $filename, $change['original'], $change['updated']);
            $themeAssets->write($writeThemeId, $filename, $change['updated']);
            $optimization->update(['status' => 'applied', 'applied_at' => now()]);
            $changedCount++;
        }

        return $changedCount;
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

        return ThemeAssetLocatorService::deferScriptTag($content, $meta['script_src']);
    }
}
