<?php

namespace App\Jobs;

use App\Exceptions\StorefrontPasswordException;
use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\ShopInstallation;
use App\Services\PlanPolicy;
use App\Services\Scanner\PageDiscoveryService;
use App\Services\Scanner\PsiClient;
use App\Services\Scanner\ScannerClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * Phase 1: one-button scan producing a score + prioritized issue list.
 * A homepage-only score misses slowdowns on product/collection templates,
 * so this scans every page PageDiscoveryService picks (plan-limited via
 * PlanPolicy::pagesPerScan) and rolls the results up onto the parent Audit,
 * unless the caller pinned it to one explicit URL (a spot-check, not a
 * full-store scan) by setting Audit.url up front. Every page is scanned on
 * both mobile and desktop by default - real user traffic and real diagnosis
 * needs both, not just one device's numbers - unless the shop has narrowed
 * that down in Settings.
 */
class RunAuditJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Up to ~6 page types x 2 devices per full-store scan - each Lighthouse
    // run is real wall-clock time, so this needs real headroom over the
    // single-page-scan default.
    public int $timeout = 600;

    // A failed scan should surface as audit.status='failed' with the
    // Dashboard's "Retry scan" button, not silently re-run itself - an
    // automatic retry of a job this expensive (up to ~14 real Lighthouse
    // runs) compounds whatever caused the failure in the first place
    // instead of recovering from it.
    public int $tries = 1;

    private string $primaryDevice = 'mobile';

    public function __construct(
        private readonly int $auditId,
    ) {
    }

    public function handle(ScannerClient $scanner, PsiClient $psi, PageDiscoveryService $discovery): void
    {
        $audit = Audit::findOrFail($this->auditId);
        $shop = $audit->shopInstallation;

        $audit->update(['source' => 'lab', 'status' => 'running']);

        $pageSpecs = $audit->url
            ? [['type' => 'custom', 'url' => $audit->url]]
            : $discovery->discover($shop, (new PlanPolicy($shop))->pagesPerScan());

        $devices = $this->devicesFor($shop);
        $this->primaryDevice = $devices[0];

        $completedPages = [];
        $thirdPartyByApp = [];
        $anyPasswordFailure = false;

        foreach ($pageSpecs as $spec) {
            foreach ($devices as $device) {
                $page = $audit->pages()->create([
                    'page_type' => $spec['type'],
                    'url' => $spec['url'],
                    'device' => $device,
                    'status' => 'running',
                ]);

                try {
                    $report = $scanner->scan($spec['url'], $shop->storefront_password, $device);
                    $this->persistPageReport($page, $report);
                    $page->update(['status' => 'complete']);
                    $completedPages[] = $page;

                    foreach ($report['thirdParty'] ?? [] as $app) {
                        $this->mergeThirdParty($thirdPartyByApp, $app);
                    }
                } catch (Throwable $e) {
                    $page->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
                    $anyPasswordFailure = $anyPasswordFailure || $e instanceof StorefrontPasswordException;
                }
            }
        }

        if (empty($completedPages)) {
            // Only a *total* failure means the saved password definitively
            // didn't work - the scanner re-submits it fresh per page/device
            // (a new browser tab each time), so one page occasionally
            // failing to unlock while the rest succeed is scanner flakiness,
            // not a wrong password, and must not re-lock a shop whose
            // password is fine (that page's own error_message already
            // surfaces the individual failure).
            if ($anyPasswordFailure && ! $shop->storefront_locked_at) {
                $shop->update(['storefront_locked_at' => now()]);
            }

            $audit->update(['status' => 'failed', 'raw_report' => ['error' => 'All page scans failed']]);
            throw new RuntimeException("Every page scan failed for audit {$audit->id}");
        }

        // At least one page reached real content, proving the storefront is
        // reachable right now - any earlier lock flag is stale.
        if ($shop->storefront_locked_at) {
            $shop->update(['storefront_locked_at' => null]);
        }

        $this->aggregateIntoAudit($audit, $completedPages);

        // app_impacts rows are recreated fresh on every scan, so a shop's
        // "Advanced delay (experimental)" choice for a script (persisted
        // separately in interceptor_delay_targets, since it can't be baked
        // into a theme file the way theme-edit disable/delay can) has to be
        // re-applied here each time, or it would silently look like it reset
        // to "Active" on the very next scan despite the interceptor still
        // running in the theme.
        $interceptorUrls = $shop->interceptorDelayTargets()->pluck('script_url')->all();

        foreach ($thirdPartyByApp as $app) {
            $url = $app['url'] ?? null;
            $isInterceptorDelayed = $url !== null && in_array($url, $interceptorUrls, true);

            $audit->appImpacts()->create([
                'app_name' => $app['name'],
                'script_url' => $url,
                'requests' => $app['requests'] ?? 0,
                'size_bytes' => $app['bytes'] ?? 0,
                'estimated_blocking_ms' => $app['blockingMs'] ?? null,
                'impact_level' => $app['impactLevel'] ?? $this->impactLevelFor($app),
                'is_platform' => $app['isPlatform'] ?? false,
                'status' => $isInterceptorDelayed ? 'delayed' : 'active',
                'delay_method' => $isInterceptorDelayed ? 'interceptor' : null,
            ]);
        }

        $primaryUrl = $completedPages[0]->url;

        $psiReport = $psi->underQuota($shop->shop_domain)
            ? $psi->spotCheck($shop->shop_domain, $primaryUrl)
            : null;

        $audit->update([
            'raw_report' => ['psi' => $psiReport],
            'category_scores' => $this->computeCategoryScores($audit->fresh()),
            'status' => 'complete',
        ]);

        if ((new PlanPolicy($shop))->canAutoFix()) {
            ApplySafeFixesJob::dispatch($shop->id, $audit->id);
        }
    }

    private function persistPageReport(AuditPage $page, array $report): void
    {
        $metrics = $report['metrics'] ?? [];
        $weight = $report['weight'] ?? [];

        $page->update([
            'score' => $report['score'] ?? null,
            'lcp' => $metrics['lcp'] ?? null,
            'inp' => $metrics['inp'] ?? null,
            'cls' => $metrics['cls'] ?? null,
            'fcp' => $metrics['fcp'] ?? null,
            'ttfb' => $metrics['ttfb'] ?? null,
            'tbt' => $metrics['tbt'] ?? null,
            'speed_index' => $metrics['speed_index'] ?? null,
            'page_weight_bytes' => $weight['page_bytes'] ?? null,
            'js_weight_bytes' => $weight['js_bytes'] ?? null,
            'css_weight_bytes' => $weight['css_bytes'] ?? null,
            'image_weight_bytes' => $weight['image_bytes'] ?? null,
            'request_count' => $weight['request_count'] ?? null,
            'screenshot' => $report['screenshot'] ?? null,
        ]);

        // Only the primary device's pass creates audit_issues - the same
        // underlying theme/app problem shows up on both devices, and
        // deduping by device here (rather than downstream) keeps
        // ApplySafeFixesJob's existing asset_key-based dedup from being the
        // only thing standing between one real issue and two redundant
        // "fixes" for it. Mobile by default, but a shop scanning desktop
        // only has no mobile pass to prefer.
        if ($page->device !== $this->primaryDevice) {
            return;
        }

        foreach ($report['issues'] ?? [] as $issue) {
            $page->audit->issues()->create([
                'audit_page_id' => $page->id,
                'category' => $issue['category'],
                'severity' => $issue['severity'],
                'title' => $issue['title'],
                'description' => $issue['description'] ?? null,
                'why' => $issue['why'] ?? null,
                'fix_available' => $issue['fixAvailable'] ?? false,
                'risk_tier' => $issue['riskTier'],
                'meta' => $issue['meta'] ?? null,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $mergeInto  keyed by app_name, mutated in place
     * @param  array<string, mixed>  $app
     */
    private function mergeThirdParty(array &$mergeInto, array $app): void
    {
        $key = $app['name'] ?? 'Unknown';

        if (! isset($mergeInto[$key])) {
            $mergeInto[$key] = $app;

            return;
        }

        // Same script found on multiple pages - keep the worst single
        // occurrence rather than summing, which would inflate its apparent
        // weight the more pages it happens to appear on.
        $existing = $mergeInto[$key];
        $mergeInto[$key] = ($app['bytes'] ?? 0) > ($existing['bytes'] ?? 0) ? $app : $existing;
    }

    /**
     * @param  array<int, AuditPage>  $pages
     */
    private function aggregateIntoAudit(Audit $audit, array $pages): void
    {
        $avg = fn (string $field) => $this->average(array_map(fn (AuditPage $p) => $p->{$field}, $pages));
        // score/*_bytes/request_count/tbt are integer columns - an averaged
        // float (e.g. 123456.5) is invalid input for Postgres's bigint/
        // tinyint, so these must round on the way in.
        $avgInt = fn (string $field) => ($v = $avg($field)) !== null ? (int) round($v) : null;

        $audit->update([
            'score' => $avgInt('score'),
            'lcp' => $avg('lcp'),
            'inp' => $avg('inp'),
            'cls' => $avg('cls'),
            'fcp' => $avg('fcp'),
            'ttfb' => $avg('ttfb'),
            'tbt' => $avgInt('tbt'),
            'speed_index' => $avg('speed_index'),
            'page_weight_bytes' => $avgInt('page_weight_bytes'),
            'js_weight_bytes' => $avgInt('js_weight_bytes'),
            'css_weight_bytes' => $avgInt('css_weight_bytes'),
            'image_weight_bytes' => $avgInt('image_weight_bytes'),
            'request_count' => $avgInt('request_count'),
        ]);
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function average(array $values): ?float
    {
        $values = array_filter($values, fn ($v) => $v !== null);

        return count($values) > 0 ? array_sum($values) / count($values) : null;
    }

    /**
     * @return array<int, string>
     */
    private function devicesFor(ShopInstallation $shop): array
    {
        return match ($shop->scan_devices) {
            'mobile' => ['mobile'],
            'desktop' => ['desktop'],
            default => ['mobile', 'desktop'],
        };
    }

    /**
     * Spec section 10: "a simple 0-100 Store Performance Score backed by
     * transparent sub-scores" across Core Web Vitals, Images, JavaScript,
     * CSS, Third-party resources and Theme. Issue-backed categories are
     * scored by deducting per-issue-severity from 100; Core Web Vitals is
     * scored from the actual averaged metric values against Google's
     * published good/needs-improvement/poor thresholds, blended with any
     * CLS root-cause issues found (a CLS issue IS a core-web-vitals problem,
     * not a separate "theme" one).
     *
     * @return array<string, int>
     */
    private function computeCategoryScores(Audit $audit): array
    {
        $issues = $audit->issues()->get();
        $appImpacts = $audit->appImpacts()->get();

        $issueDeduction = fn (string $category) => $this->deductionScore(
            $issues->where('category', $category)->pluck('severity')->all()
        );

        $cwvMetricScore = $this->average(array_filter([
            $this->metricScore($audit->lcp !== null ? $audit->lcp * 1000 : null, 2500, 4000),
            $this->metricScore($audit->inp, 200, 500),
            $this->metricScore($audit->cls, 0.1, 0.25),
            $this->metricScore($audit->tbt, 200, 600),
        ], fn ($v) => $v !== null));

        $clsIssueScore = $issueDeduction('cls');
        $coreWebVitals = $cwvMetricScore !== null
            ? (int) round(($cwvMetricScore + $clsIssueScore) / 2)
            : $clsIssueScore;

        $thirdPartyDeduction = $appImpacts->reduce(function (int $carry, $app) {
            return $carry + match ($app->impact_level) {
                'high' => 20,
                'medium' => 10,
                default => 4,
            };
        }, 0);

        return [
            'core_web_vitals' => $coreWebVitals,
            'images' => $issueDeduction('image'),
            'javascript' => $issueDeduction('js'),
            'css' => $issueDeduction('css'),
            'theme' => $issueDeduction('theme'),
            'third_party' => max(0, 100 - $thirdPartyDeduction),
        ];
    }

    /**
     * @param  array<int, string>  $severities
     */
    private function deductionScore(array $severities): int
    {
        $weights = ['critical' => 30, 'high' => 18, 'medium' => 9, 'low' => 4];
        $deduction = array_sum(array_map(fn ($s) => $weights[$s] ?? 9, $severities));

        return max(0, 100 - $deduction);
    }

    /**
     * Linear score between Google's CWV "good" and "poor" thresholds - 100
     * at/under good, 40 at poor, continuing to taper to 0 by 2x poor rather
     * than clamping at 40 forever, so a truly catastrophic value still reads
     * as truly catastrophic rather than "just needs improvement."
     */
    private function metricScore(?float $value, float $good, float $poor): ?float
    {
        if ($value === null) {
            return null;
        }

        if ($value <= $good) {
            return 100.0;
        }

        if ($value <= $poor) {
            return 100 - (($value - $good) / ($poor - $good)) * 60;
        }

        if ($value >= $poor * 2) {
            return 0.0;
        }

        return 40 - (($value - $poor) / $poor) * 40;
    }

    private function impactLevelFor(array $app): string
    {
        $bytes = $app['bytes'] ?? 0;

        return match (true) {
            $bytes > 300_000 => 'high',
            $bytes > 100_000 => 'medium',
            default => 'low',
        };
    }
}
