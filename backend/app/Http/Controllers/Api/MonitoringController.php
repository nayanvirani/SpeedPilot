<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditPage;
use App\Models\ShopInstallation;
use App\Services\Monitoring\MonthlyReportService;
use App\Services\PlanPolicy;
use Illuminate\Http\Request;

class MonitoringController extends Controller
{
    public function trend(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');
        $policy = new PlanPolicy($shop);

        $runs = $shop->monitoringRuns()
            ->with('audit:id,score,created_at')
            ->where('created_at', '>=', now()->subDays($policy->historyDays() ?: 7))
            ->orderBy('run_at')
            ->get();

        return response()->json(['monitoring_runs' => $runs]);
    }

    /**
     * The "should I open this app today" signal for the Dashboard - reads
     * what RunMonitoringJob already computed and persisted rather than
     * recomputing anything here.
     */
    public function health(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');
        $policy = new PlanPolicy($shop);

        // Re-checked independently of trend()/report() - a shop downgraded
        // off monitoring shouldn't keep showing stale health from before.
        if (! $policy->monitoringLevel()) {
            return response()->json(['status' => 'unknown', 'score' => null, 'trend_delta' => null, 'run_at' => null]);
        }

        $lastRun = $shop->monitoringRuns()->with('audit:id,score')->latest('run_at')->first();

        if (! $lastRun) {
            return response()->json(['status' => 'unknown', 'score' => null, 'trend_delta' => null, 'run_at' => null]);
        }

        $status = match (true) {
            $lastRun->is_regression => 'critical',
            $lastRun->trend_delta !== null && $lastRun->trend_delta < 0 => 'attention',
            default => 'healthy',
        };

        return response()->json([
            'status' => $status,
            'score' => $lastRun->audit?->score,
            'trend_delta' => $lastRun->trend_delta,
            'run_at' => $lastRun->run_at,
            'new_third_party_scripts' => count($lastRun->diff_summary['new_third_party_scripts'] ?? []),
            'new_issues' => count($lastRun->diff_summary['new_issues'] ?? []),
        ]);
    }

    /**
     * Per-page-type score trend (Homepage, Product, Collection, ...) across
     * audits, instead of just the one aggregate line trend() already gives -
     * lets a merchant see "collection pages got worse" even when the
     * overall score looks stable.
     */
    public function pageTrend(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');
        $policy = new PlanPolicy($shop);

        $rows = AuditPage::query()
            ->join('audits', 'audits.id', '=', 'audit_pages.audit_id')
            ->where('audits.shop_installation_id', $shop->id)
            ->where('audits.status', 'complete')
            ->where('audits.created_at', '>=', now()->subDays($policy->historyDays() ?: 7))
            ->orderBy('audits.created_at')
            ->get([
                'audit_pages.page_type',
                'audit_pages.score',
                'audits.id as audit_id',
                'audits.created_at as audit_created_at',
            ]);

        $series = $rows->groupBy('page_type')->map(function ($pageRows) {
            return $pageRows->groupBy('audit_id')->map(function ($group) {
                $scores = $group->pluck('score')->filter(fn ($s) => $s !== null);

                return [
                    'audit_id' => $group->first()->audit_id,
                    'created_at' => $group->first()->audit_created_at,
                    'score' => $scores->count() > 0 ? (int) round($scores->avg()) : null,
                ];
            })->values();
        });

        return response()->json([
            'page_types' => $series->keys()->values(),
            'series' => $series,
        ]);
    }

    /**
     * The recurring monthly proof point - defaults to the current month,
     * but any past month can be requested to answer "why did my store get
     * slower last month".
     */
    public function report(Request $request, MonthlyReportService $reports)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        return response()->json($reports->build($shop, $request->query('month')));
    }
}
