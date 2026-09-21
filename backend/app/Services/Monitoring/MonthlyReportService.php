<?php

namespace App\Services\Monitoring;

use App\Models\ShopInstallation;
use Carbon\CarbonImmutable;

/**
 * The recurring proof point behind the monitoring subscription: "here's
 * what changed this month" instead of a one-time before/after. Shared by
 * the on-demand /monitoring/report endpoint (any month) and the scheduled
 * monthly Slack digest (always the month that just ended), so there's one
 * place computing this rather than two copies to keep in sync.
 */
class MonthlyReportService
{
    public function __construct(private readonly AuditDiffService $diffService)
    {
    }

    /**
     * @param  string|null  $month  "YYYY-MM", defaults to the current month
     */
    public function build(ShopInstallation $shop, ?string $month = null): array
    {
        $period = $month
            ? CarbonImmutable::createFromFormat('Y-m', $month)->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();
        $start = $period->startOfMonth();
        $end = $period->endOfMonth();

        $lastAudit = $shop->audits()->where('status', 'complete')
            ->whereBetween('created_at', [$start, $end])
            ->latest('created_at')
            ->first();

        if (! $lastAudit) {
            return ['month' => $period->format('Y-m'), 'has_data' => false];
        }

        // True before/after: the latest complete audit strictly *before*
        // this month, not just "first audit in the month" - a shop scanned
        // daily could already be mid-improvement by day 1.
        $beforeAudit = $shop->audits()->where('status', 'complete')
            ->where('created_at', '<', $start)
            ->latest('created_at')
            ->first() ?? $lastAudit;

        $diff = $this->diffService->diff(
            $beforeAudit->load('appImpacts', 'issues', 'pages'),
            $lastAudit->load('appImpacts', 'issues', 'pages'),
        );

        $regressions = $shop->monitoringRuns()
            ->where('is_regression', true)
            ->whereBetween('run_at', [$start, $end])
            ->get(['id', 'audit_id', 'run_at', 'trend_delta']);

        $optimizations = $shop->optimizations()
            ->where('status', 'applied')
            ->whereBetween('applied_at', [$start, $end])
            ->get(['id', 'type', 'risk_tier', 'applied_at']);

        return [
            'month' => $period->format('Y-m'),
            'has_data' => true,
            'score' => [
                'before' => $beforeAudit->score,
                'after' => $lastAudit->score,
                'delta' => $diff['score_delta'],
            ],
            'metrics' => $diff['metrics'],
            'issues_resolved' => $diff['resolved_issues'],
            'issues_new' => $diff['new_issues'],
            'regressions' => $regressions,
            'optimizations_applied' => $optimizations,
        ];
    }
}
