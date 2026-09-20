<?php

namespace App\Jobs;

use App\Models\Audit;
use App\Services\PlanPolicy;
use App\Services\Scanner\PsiClient;
use App\Services\Scanner\ScannerClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Phase 1: one-button scan producing a score + prioritized issue list.
 * Orchestrates the Node scanner (Lighthouse/DOM analysis) and, if quota
 * allows, a PSI spot check, then persists audit_issues + app_impacts.
 */
class RunAuditJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public function __construct(
        private readonly int $auditId,
    ) {
    }

    public function handle(ScannerClient $scanner, PsiClient $psi): void
    {
        $audit = Audit::findOrFail($this->auditId);
        $shop = $audit->shopInstallation;

        $audit->update(['source' => 'lab', 'status' => 'running']);

        try {
            $report = $scanner->scan($audit->url);

            $psiReport = $psi->underQuota($shop->shop_domain)
                ? $psi->spotCheck($shop->shop_domain, $audit->url)
                : null;

            $this->persistReport($audit, $report, $psiReport);

            $audit->update(['status' => 'complete']);

            if ((new PlanPolicy($shop))->canAutoFix()) {
                ApplySafeFixesJob::dispatch($shop->id, $audit->id);
            }
        } catch (Throwable $e) {
            $audit->update(['status' => 'failed', 'raw_report' => ['error' => $e->getMessage()]]);
            throw $e;
        }
    }

    private function persistReport(Audit $audit, array $report, ?array $psiReport): void
    {
        $metrics = $report['metrics'] ?? [];
        $weight = $report['weight'] ?? [];

        $audit->update([
            'score' => $report['score'] ?? null,
            'lcp' => $metrics['lcp'] ?? null,
            'inp' => $metrics['inp'] ?? null,
            'cls' => $metrics['cls'] ?? null,
            'fcp' => $metrics['fcp'] ?? null,
            'ttfb' => $metrics['ttfb'] ?? null,
            'page_weight_bytes' => $weight['page_bytes'] ?? null,
            'js_weight_bytes' => $weight['js_bytes'] ?? null,
            'css_weight_bytes' => $weight['css_bytes'] ?? null,
            'raw_report' => ['scanner' => $report, 'psi' => $psiReport],
        ]);

        foreach ($report['issues'] ?? [] as $issue) {
            $audit->issues()->create([
                'category' => $issue['category'],
                'severity' => $issue['severity'],
                'title' => $issue['title'],
                'description' => $issue['description'] ?? null,
                'fix_available' => $issue['fixAvailable'] ?? false,
                'risk_tier' => $issue['riskTier'],
                'meta' => $issue['meta'] ?? null,
            ]);
        }

        foreach ($report['thirdParty'] ?? [] as $app) {
            $audit->appImpacts()->create([
                'app_name' => $app['name'],
                'script_url' => $app['url'] ?? null,
                'requests' => $app['requests'] ?? 0,
                'size_bytes' => $app['bytes'] ?? 0,
                'estimated_blocking_ms' => $app['blockingMs'] ?? null,
                'impact_level' => $app['impactLevel'] ?? $this->impactLevelFor($app),
            ]);
        }
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
