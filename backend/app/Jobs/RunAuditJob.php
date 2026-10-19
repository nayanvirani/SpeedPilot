<?php

namespace App\Jobs;

use App\Exceptions\StorefrontPasswordException;
use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\ShopInstallation;
use App\Services\Monitoring\MonitoringRecorder;
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
            ? [['type' => $audit->url_page_type ?? 'custom', 'url' => $audit->url]]
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
        // "Advanced delay (experimental)" / "Stop (verified)" choice for a
        // script (persisted separately in interceptor_delay_targets /
        // content_stop_targets, since neither can be baked into a theme
        // file the way theme-edit disable/delay can) has to be re-applied
        // here each time, or it would silently look like it reset to
        // "Active" on the very next scan despite still being in effect.
        $interceptorUrls = $shop->interceptorDelayTargets()->pluck('script_url')->all();
        $contentStopTargets = $shop->contentStopTargets()->get()->keyBy('script_url');

        foreach ($thirdPartyByApp as $app) {
            $url = $app['url'] ?? null;
            // Checks every URL this app loaded this scan, not just the
            // primary one - a merchant who turned delay on when a
            // different file happened to be "the" script_url shouldn't see
            // the badge silently flip back to "Active" just because a
            // different file won this scan's byte-weight race.
            $isInterceptorDelayed = ! empty(array_intersect(
                array_filter([$url, ...($app['relatedUrls'] ?? [])]),
                $interceptorUrls,
            ));
            $stopTarget = $url !== null ? $contentStopTargets->get($url) : null;

            // Hashed asset filenames rotate between scans (observed live
            // this session) - refresh the stored search text whenever this
            // scan sees a different but still-present match, or the Liquid
            // `replace` this target drives would quietly stop matching
            // anything without ever surfacing an error.
            if ($stopTarget && ! empty($app['staticMatch']) && $app['staticMatch'] !== $stopTarget->source_snippet) {
                $stopTarget->update(['source_snippet' => $app['staticMatch']]);
            }

            $audit->appImpacts()->create([
                'app_name' => $app['name'],
                'script_url' => $url,
                'related_script_urls' => ! empty($app['relatedUrls']) ? array_values($app['relatedUrls']) : null,
                'requests' => $app['requests'] ?? 0,
                'size_bytes' => $app['bytes'] ?? 0,
                'estimated_blocking_ms' => $app['blockingMs'] ?? null,
                'impact_level' => $app['impactLevel'] ?? $this->impactLevelFor($app),
                'is_platform' => $app['isPlatform'] ?? false,
                'content_for_header_match' => $app['staticMatch'] ?? null,
                'status' => match (true) {
                    $stopTarget !== null => 'delayed',
                    $isInterceptorDelayed => 'delayed',
                    default => 'active',
                },
                'delay_method' => match (true) {
                    $stopTarget !== null => 'content_replace',
                    $isInterceptorDelayed => 'interceptor',
                    default => null,
                },
            ]);
        }

        // "Unused JS" (and similar) issues can only ever be reviewed
        // manually - SpeedPilot doesn't own the contents of a third-party
        // app's own file, so there's no minify/tree-shake fix to offer.
        // The one real "fix" is the same Disable/Delay/Advanced Delay
        // already on the App & Script Impact page for that same app, which
        // this issue's raw URL (see domAnalyzer.js's scriptIssues()) can be
        // matched to by hostname - done here, once, after every app_impact
        // for this audit already exists, rather than per-page during
        // persistPageReport() where they don't yet.
        $nonPlatformImpacts = $audit->appImpacts()->where('is_platform', false)->get();

        $audit->issues()->where('category', 'js')->get()->each(function ($issue) use ($nonPlatformImpacts) {
            $url = $issue->meta['url'] ?? null;
            $host = $url ? parse_url($url, PHP_URL_HOST) : null;

            if (! $host) {
                return;
            }

            $matched = $nonPlatformImpacts->first(function ($impact) use ($host) {
                $impactHost = $impact->script_url ? parse_url($impact->script_url, PHP_URL_HOST) : null;

                return $impactHost && ($impactHost === $host || str_ends_with($host, ".{$impactHost}") || str_ends_with($impactHost, ".{$host}"));
            });

            if ($matched) {
                $issue->update(['meta' => [...$issue->meta, 'matched_app_impact_id' => $matched->id, 'matched_app_name' => $matched->app_name]]);
            }
        });

        $primaryUrl = $completedPages[0]->url;

        $psiReport = $psi->underQuota($shop->shop_domain)
            ? $psi->spotCheck($shop->shop_domain, $primaryUrl)
            : null;

        $audit->update([
            'raw_report' => ['psi' => $psiReport],
            'category_scores' => $this->computeCategoryScores($audit->fresh()),
            'status' => 'complete',
        ]);

        // Applying safe fixes is merchant-initiated only (the "Fix Safe
        // Issues" button on the audit page, via AuditController::
        // applySafeFixes()) - this used to auto-dispatch here on every
        // completed scan, including the verification audit ApplySafeFixesJob
        // itself creates after applying fixes. That verification audit
        // completing re-triggered this same dispatch, which re-swept the
        // *live* (unmodified) theme, found the same lazy-load candidates
        // again, and wrote a fresh "applied" Optimization row for them -
        // repeating every few minutes forever (confirmed live: the same file
        // showing 5+ duplicate "applied" rows on jewel-nests). Making this
        // strictly opt-in via the button removes the loop at its source
        // instead of just patching the dedup.

        // Every completed scan updates monitoring data, not just ones the
        // daily schedule happened to trigger - MonitoringRecorder is
        // idempotent per audit_id, so RunMonitoringJob's own scheduled scan
        // (which also runs through this same job) doesn't get recorded
        // twice.
        app(MonitoringRecorder::class)->record($shop, $audit->fresh());
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

        // Shopify intentionally noindexes cart and search-results pages by
        // default - that's correct, standard practice (parameterized/
        // duplicate content nobody wants ranking), not a merchant mistake,
        // so flagging it as "blocked from search engines" on these two page
        // types specifically would be a false alarm. Confirmed live:
        // jewel-nests' real /cart and /search pages both tripped this on
        // first use, neither one an actual problem.
        $isCrawlableNoise = fn (array $issue) => ($issue['meta']['lighthouse_audit'] ?? null) === 'is-crawlable'
            && in_array($page->page_type, ['cart', 'search'], true);

        foreach ($report['issues'] ?? [] as $issue) {
            if ($isCrawlableNoise($issue)) {
                continue;
            }

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
        $winner = ($app['bytes'] ?? 0) > ($existing['bytes'] ?? 0) ? $app : $existing;

        // A literal content_for_header match on any scanned page is real
        // evidence, regardless of which page "won" on byte weight - a miss
        // on the winning page shouldn't hide a hit found on another one.
        $winner['staticMatch'] = $app['staticMatch'] ?? $existing['staticMatch'] ?? null;

        // The LOSING page's own url would otherwise vanish entirely, not
        // just its relatedUrls - if page A's biggest Judge.me file beats
        // page B's, page B's file is still a real Judge.me file, just one
        // this merge didn't pick as "the" url for this app.
        $winner['relatedUrls'] = array_values(array_unique(array_filter([
            ...($existing['relatedUrls'] ?? []), $existing['url'] ?? null,
            ...($app['relatedUrls'] ?? []), $app['url'] ?? null,
        ], fn ($u) => $u !== null && $u !== $winner['url'])));

        $mergeInto[$key] = $winner;
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

        // Same multiplicative-decay reasoning as deductionScore() above, not
        // a flat sum - a store with 3 high-impact apps shouldn't score
        // identically to one with 20.
        $thirdPartyScore = $appImpacts->reduce(function (float $carry, $app) {
            $factor = match ($app->impact_level) {
                'high' => 0.20,
                'medium' => 0.10,
                default => 0.04,
            };

            return $carry * (1 - $factor);
        }, 100.0);

        return [
            'core_web_vitals' => $coreWebVitals,
            'images' => $issueDeduction('image'),
            'javascript' => $issueDeduction('js'),
            'css' => $issueDeduction('css'),
            'theme' => $issueDeduction('theme'),
            'third_party' => (int) round($thirdPartyScore),
            'seo' => $issueDeduction('seo'),
        ];
    }

    /**
     * Multiplicative, not a flat subtraction - each issue shaves its
     * severity's fraction off whatever score remains, rather than a fixed
     * number of points off 100 directly. A flat sum hits its max(0, ...)
     * floor the moment a category crosses ~6 high-severity or ~11
     * medium-severity issues (18*6=108, 9*11=99), and every category past
     * that point reads as an identical "0" regardless of whether it has 12
     * issues or 97 - confirmed on a real audit (jewel-nests) where 5 of 7
     * categories all showed 0 despite js (97 issues) being drastically worse
     * than css (16 issues), with no way to tell from the score alone. This
     * keeps moving (asymptotically toward, never instantly to, zero), so a
     * category with many more issues than another still reads as worse,
     * even once both are "clearly bad."
     *
     * @param  array<int, string>  $severities
     */
    private function deductionScore(array $severities): int
    {
        $weights = ['critical' => 0.30, 'high' => 0.18, 'medium' => 0.09, 'low' => 0.04];

        $score = array_reduce(
            $severities,
            fn (float $carry, string $severity) => $carry * (1 - ($weights[$severity] ?? 0.09)),
            100.0,
        );

        return (int) round($score);
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

    /**
     * Laravel calls this when the job is definitively done retrying -
     * including a case handle() never gets a chance to clean up itself: a
     * worker container killed mid-scan (e.g. a deploy restarting the
     * service) abandons the job with no graceful shutdown, so with
     * tries=1 the next worker that notices the expired reservation marks
     * it failed here, in a fresh process, rather than handle() ever running
     * again. Without this, the audit (and whichever page was mid-scan when
     * the worker died) stayed stuck at 'running' forever - no error, no
     * "Retry scan" button, nothing for the merchant to act on.
     */
    public function failed(?Throwable $exception): void
    {
        $audit = Audit::find($this->auditId);

        if (! $audit || in_array($audit->status, ['complete', 'failed'], true)) {
            return;
        }

        $audit->pages()->where('status', 'running')->update([
            'status' => 'failed',
            'error_message' => 'The scan was interrupted before this page finished.',
        ]);

        $audit->update([
            'status' => 'failed',
            'raw_report' => ['error' => 'The scan was interrupted before it could finish - '.($exception?->getMessage() ?? 'unknown reason')],
        ]);
    }
}
