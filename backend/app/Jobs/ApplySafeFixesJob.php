<?php

namespace App\Jobs;

use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\ShopInstallation;
use App\Services\PlanPolicy;
use App\Services\Shopify\AccessTokenExpiredException;
use App\Services\Shopify\AssetBackupService;
use App\Services\Shopify\FontDisplaySweeper;
use App\Services\Shopify\ImageLazyLoadSweeper;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Models\OptimizedTheme;
use App\Services\Shopify\ThemeAssetLocatorService;
use App\Services\Shopify\ThemeAssetService;
use App\Services\Shopify\ThemeDuplicateService;
use App\Services\Shopify\ThemeWriteAccessDeniedException;
use App\Services\SlackNotifier;
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
        // Extra cap on top of the plan's own autoFixLimit() - used by the
        // synchronous on-demand endpoint to bound how long one HTTP request
        // can run (each fix is several sequential Shopify API calls).
        private readonly ?int $maxIssues = null,
        // Set by the per-issue "Auto fix on theme" button (AuditController::
        // applySingleSafeFix) - targets exactly this one issue, ignoring
        // both autoFixLimit() and $maxIssues, since a merchant explicitly
        // choosing one specific fix isn't the bulk sweep those caps exist
        // to bound.
        private readonly ?int $onlyIssueId = null,
    ) {
    }

    public function handle(AssetBackupService $backups, SlackNotifier $slack): void
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

        // A live HTTP request (e.g. AuditController's synchronous "Fix Safe
        // Issues" button) always has a fresh token by the time it gets here
        // - VerifyShopifySessionToken already refreshed it. This job can
        // also run from the daily monitoring schedule though, with no live
        // session token available to refresh an expired one - checking
        // upfront avoids several doomed API calls for a condition that only
        // resolves itself once the merchant next opens the app.
        if ($shop->needsFreshAccessToken()) {
            $shop->update(['needs_reauth_at' => now()]);

            return;
        }

        $themeAssets = new ThemeAssetService(
            new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token)
        );
        $locator = new ThemeAssetLocatorService($themeAssets);
        $sweeper = new ImageLazyLoadSweeper($themeAssets);
        $fontSweeper = new FontDisplaySweeper($themeAssets);

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

        if ($this->onlyIssueId !== null) {
            // A single explicit target from the per-issue "Auto fix on
            // theme" button - fetched directly, bypassing the dedup below
            // entirely. That dedup collapses every lazy_load_sweep issue
            // across all pages down to just one (they share the same
            // fix_type, no asset_key), so filtering it *after* the collapse
            // silently dropped this exact issue whenever it wasn't the one
            // survivor - the job then ran with an empty collection and
            // silently did nothing, which the controller reported as a
            // generic "couldn't apply this" (confirmed live on jewel-nests:
            // clicking the button on the Collection-page instance of a
            // Lazy-load candidate issue no-opped, while the Homepage
            // instance of the exact same fix worked).
            $safeIssues = $audit->issues()
                ->where('id', $this->onlyIssueId)
                ->where('risk_tier', 'safe')
                ->where('fix_available', true)
                ->get();
        } else {
            $safeIssues = $audit->issues()
                ->where('risk_tier', 'safe')
                ->where('fix_available', true)
                ->get()
                // A multi-page scan can report the same underlying fix once
                // per page it appears on (e.g. a shared header script, or an
                // image lazy-load sweep that isn't tied to one specific
                // image at all) - applying it again per duplicate would just
                // waste the plan's auto-fix limit on the same underlying
                // change.
                ->unique(fn (AuditIssue $issue) => $issue->meta['asset_key']
                    ?? $issue->meta['fix_type']
                    ?? $issue->id);

            $limit = $policy->autoFixLimit();
            if ($limit !== null) {
                $safeIssues = $safeIssues->take($limit);
            }

            if ($this->maxIssues !== null) {
                $safeIssues = $safeIssues->take($this->maxIssues);
            }
        }

        foreach ($safeIssues as $issue) {
            try {
                $fixType = $issue->meta['fix_type'] ?? null;

                if ($fixType === 'lazy_load_sweep') {
                    $appliedCount += $this->applySweep($shop, $issue, $liveThemeId, $writeThemeId, $themeAssets, $backups, $sweeper, 'lazy_load');

                    continue;
                }

                if ($fixType === 'font_display_sweep') {
                    $appliedCount += $this->applySweep($shop, $issue, $liveThemeId, $writeThemeId, $themeAssets, $backups, $fontSweeper, 'font_display_sweep');

                    continue;
                }

                $assetKey = $issue->meta['asset_key'] ?? null;

                if (! $assetKey && $fixType === 'defer_script' && isset($issue->meta['script_src'])) {
                    $assetKey = $locator->findTagSource($liveThemeId, $issue->meta['script_src']);
                }

                if (! $assetKey) {
                    continue; // couldn't resolve a real file to edit - recommendation-only
                }

                // A re-scan reports the same underlying issue again on a
                // fresh AuditIssue row - without this, re-running (the
                // bulk "Fix Safe Issues" button, or a per-issue "Auto fix on
                // theme" click on that new issue) would rewrite an
                // already-fixed file from the pristine original every time,
                // silently re-deferring an already-deferred script on every
                // call.
                $alreadyApplied = $shop->optimizations()
                    ->where('asset_key', $assetKey)
                    ->where('type', $fixType ?? $issue->category)
                    ->where('status', 'applied')
                    ->first();

                if ($alreadyApplied) {
                    // Relink to whichever issue this call is actually about
                    // - otherwise a per-issue "Auto fix on theme" click for
                    // a *new* scan's issue on an already-fixed file found no
                    // optimization row to report back (still linked to
                    // whichever older audit first applied it), and the
                    // single-issue endpoint wrongly reported "couldn't
                    // apply this" for a file that's already fixed.
                    if ($alreadyApplied->audit_issue_id !== $issue->id) {
                        $alreadyApplied->update(['audit_issue_id' => $issue->id]);
                    }

                    continue;
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
            } catch (AccessTokenExpiredException) {
                // Rare mid-job race with the upfront needsFreshAccessToken()
                // check above (the token expired between the check and this
                // write) - same "stop, don't churn through more doomed
                // calls" response, just a self-resolving condition instead
                // of a permanent gate.
                $shop->update(['needs_reauth_at' => now()]);

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
                // It's really the homepage (on the preview theme when
                // duplicating) being re-checked, not a merchant-chosen
                // custom URL - without this, the Dashboard's "Score by page"
                // mislabels it "Custom URL" (confirmed live on jewel-nests).
                'url_page_type' => $verifyUrl ? 'home' : null,
                'status' => 'pending',
            ]);

            RunAuditJob::dispatch($verificationAudit->id);

            $slack->send($shop, sprintf(
                ':white_check_mark: SpeedPilot applied %d safe fix%s to %s automatically. Verifying the result now - check the Optimizations page to review or roll back.',
                $appliedCount,
                $appliedCount === 1 ? '' : 'es',
                $shop->shop_domain,
            ));
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
    /**
     * Shared by every "sweep the whole theme, no single locatable asset_key"
     * fix type (lazy-load images, font-display) - the sweeper differs, but
     * the dedup/reuse/backup logic around it is identical.
     */
    private function applySweep(
        ShopInstallation $shop,
        AuditIssue $issue,
        string $liveThemeId,
        string $writeThemeId,
        ThemeAssetService $themeAssets,
        AssetBackupService $backups,
        ImageLazyLoadSweeper|FontDisplaySweeper $sweeper,
        string $type,
    ): int {
        $changedCount = 0;

        foreach ($sweeper->sweep($liveThemeId) as $filename => $change) {
            // sweep() always reads the *live* theme, which stays unmodified
            // when writing to a preview duplicate - so a file already fixed
            // on the duplicate still shows up as a "candidate" on every
            // later call. Without this check, each call created a brand new
            // "applied" Optimization row for the same file (confirmed live:
            // the same file racking up 5+ duplicate rows a few minutes
            // apart on jewel-nests) instead of recognizing it was already
            // done - the same already-applied guard every other fix type
            // gets below, just missing here until now.
            $alreadyApplied = $shop->optimizations()
                ->where('type', $type)
                ->where('asset_key', $filename)
                ->where('status', 'applied')
                ->first();

            if ($alreadyApplied) {
                // Relink to whichever issue this call is actually about -
                // without this, clicking "Auto fix on theme" on a *new*
                // scan's issue for an already-fixed file found nothing to
                // return (the applied row was still linked to whichever
                // older audit first applied it), so the per-issue endpoint
                // reported "couldn't apply this" even though the file
                // already has the fix (confirmed live on jewel-nests: a
                // real theme-write-access shop, not a permissions problem).
                if ($alreadyApplied->audit_issue_id !== $issue->id) {
                    $alreadyApplied->update(['audit_issue_id' => $issue->id]);
                }

                continue;
            }

            // Reuse a still-pending attempt from an earlier blocked scan
            // instead of creating a new one every time - without this, a
            // scan re-running while write_themes stays unapproved (a
            // normal, repeated state, not a one-off) piled up a fresh
            // "recommended, never applied" row per file on every scan, each
            // showing whatever the fix logic produced *at that time* - so a
            // merchant reviewing "View change" could be looking at a stale
            // diff from a since-fixed bug in the fix logic itself instead
            // of what would actually be applied today.
            $optimization = $shop->optimizations()
                ->where('type', $type)
                ->where('asset_key', $filename)
                ->where('status', 'recommended')
                ->first();

            if ($optimization) {
                $optimization->backups()->delete();
            } else {
                $optimization = $shop->optimizations()->create([
                    'audit_issue_id' => $issue->id,
                    'type' => $type,
                    'risk_tier' => 'safe',
                    'status' => 'recommended',
                    'theme_id' => $writeThemeId,
                    'asset_key' => $filename,
                ]);
            }

            $backups->backup($optimization, $writeThemeId, $filename, $change['original'], $change['updated']);
            $themeAssets->write($writeThemeId, $filename, $change['updated']);
            $optimization->update(['status' => 'applied', 'applied_at' => now(), 'audit_issue_id' => $issue->id]);
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
