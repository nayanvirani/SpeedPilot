<?php

namespace App\Jobs;

use App\Models\Audit;
use App\Models\AuditPage;
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
 * full-store scan) by setting Audit.url up front.
 */
class RunAuditJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

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

        $completedPages = [];
        $thirdPartyByApp = [];

        foreach ($pageSpecs as $spec) {
            $page = $audit->pages()->create([
                'page_type' => $spec['type'],
                'url' => $spec['url'],
                'status' => 'running',
            ]);

            try {
                $report = $scanner->scan($spec['url'], $shop->storefront_password);
                $this->persistPageReport($page, $report);
                $page->update(['status' => 'complete']);
                $completedPages[] = $page;

                foreach ($report['thirdParty'] ?? [] as $app) {
                    $this->mergeThirdParty($thirdPartyByApp, $app);
                }
            } catch (Throwable $e) {
                $page->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            }
        }

        if (empty($completedPages)) {
            $audit->update(['status' => 'failed', 'raw_report' => ['error' => 'All page scans failed']]);
            throw new RuntimeException("Every page scan failed for audit {$audit->id}");
        }

        $this->aggregateIntoAudit($audit, $completedPages);

        foreach ($thirdPartyByApp as $app) {
            $audit->appImpacts()->create([
                'app_name' => $app['name'],
                'script_url' => $app['url'] ?? null,
                'requests' => $app['requests'] ?? 0,
                'size_bytes' => $app['bytes'] ?? 0,
                'estimated_blocking_ms' => $app['blockingMs'] ?? null,
                'impact_level' => $app['impactLevel'] ?? $this->impactLevelFor($app),
            ]);
        }

        $primaryUrl = $completedPages[0]->url;

        $psiReport = $psi->underQuota($shop->shop_domain)
            ? $psi->spotCheck($shop->shop_domain, $primaryUrl)
            : null;

        $audit->update([
            'raw_report' => ['psi' => $psiReport],
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
            'page_weight_bytes' => $weight['page_bytes'] ?? null,
            'js_weight_bytes' => $weight['js_bytes'] ?? null,
            'css_weight_bytes' => $weight['css_bytes'] ?? null,
            'screenshot' => $report['screenshot'] ?? null,
        ]);

        foreach ($report['issues'] ?? [] as $issue) {
            $page->audit->issues()->create([
                'audit_page_id' => $page->id,
                'category' => $issue['category'],
                'severity' => $issue['severity'],
                'title' => $issue['title'],
                'description' => $issue['description'] ?? null,
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
        // score/page_weight_bytes/js_weight_bytes/css_weight_bytes are integer
        // columns - an averaged float (e.g. 123456.5) is invalid input for
        // Postgres's bigint/tinyint, so these must round on the way in.
        $avgInt = fn (string $field) => ($v = $avg($field)) !== null ? (int) round($v) : null;

        $audit->update([
            'score' => $avgInt('score'),
            'lcp' => $avg('lcp'),
            'inp' => $avg('inp'),
            'cls' => $avg('cls'),
            'fcp' => $avg('fcp'),
            'ttfb' => $avg('ttfb'),
            'page_weight_bytes' => $avgInt('page_weight_bytes'),
            'js_weight_bytes' => $avgInt('js_weight_bytes'),
            'css_weight_bytes' => $avgInt('css_weight_bytes'),
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
