<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\ShopInstallation;
use App\Services\Monitoring\AuditDiffService;
use App\Services\Monitoring\MonthlyReportService;
use App\Services\Monitoring\RevenueImpactEstimator;
use App\Services\PlanPolicy;
use Illuminate\Http\Request;

class MonitoringController extends Controller
{
    /**
     * The concrete, plain-language proof point for the Dashboard: not an
     * abstract score, but "your homepage loaded in Xs, now it loads in Ys" -
     * the very first completed scan (before SpeedPilot touched anything)
     * against the most recent one. LCP is the headline number since it's
     * the metric that actually answers "how long until this feels loaded"
     * to a real shopper; FCP/Speed Index ride along as secondary context.
     */
    public function beforeAfter(Request $request, RevenueImpactEstimator $estimator)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $summarize = fn (?Audit $audit) => $audit ? [
            'audit_id' => $audit->id,
            'created_at' => $audit->created_at,
            'score' => $audit->score,
            'lcp' => $audit->lcp !== null ? (float) $audit->lcp : null,
            'fcp' => $audit->fcp !== null ? (float) $audit->fcp : null,
            'speed_index' => $audit->speed_index !== null ? (float) $audit->speed_index : null,
        ] : null;

        $before = $shop->audits()->where('status', 'complete')->oldest('created_at')->first();
        $after = $shop->audits()->where('status', 'complete')->latest('created_at')->first();

        // A single completed scan has nothing to compare against yet -
        // showing it as both "before" and "after" would falsely claim a
        // 0.0s improvement instead of "not enough data".
        if ($before && $after && $before->id === $after->id) {
            $after = null;
        }

        return response()->json([
            'before' => $summarize($before),
            'after' => $summarize($after),
            'estimated_impact' => ($before && $after)
                ? $estimator->estimate(
                    $shop,
                    $before->lcp !== null ? (float) $before->lcp : null,
                    $after->lcp !== null ? (float) $after->lcp : null,
                )
                : null,
        ]);
    }

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
     * The Dashboard headline: "you lost/gained N points this week, here's
     * why" - the same regression-detection diff MonitoringRecorder already
     * sends to Slack on a day-to-day drop, but rolled up over 7 days and
     * surfaced prominently in the app itself, not only as a Slack message a
     * merchant might miss. Compares the latest run against whichever run is
     * oldest within the last 7 days (not a straight sum of daily diffs) -
     * one clean before/after over the week, not compounding noise across
     * several runs.
     */
    public function weeklyRollup(Request $request, AuditDiffService $diffService)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $latestRun = $shop->monitoringRuns()->with('audit')->latest('run_at')->first();

        if (! $latestRun || ! $latestRun->audit?->isComplete()) {
            return response()->json(['has_data' => false]);
        }

        // The oldest run still within the last 7 days - not "exactly 7 days
        // ago", since monitoring runs don't land on exact daily boundaries
        // (a weekly-frequency shop, a manual scan mid-week, a skipped day).
        $weekAgoRun = $shop->monitoringRuns()->with('audit')
            ->where('run_at', '<=', now()->subDays(7))
            ->where('id', '!=', $latestRun->id)
            ->latest('run_at')
            ->first();

        if (! $weekAgoRun || ! $weekAgoRun->audit?->isComplete()) {
            // Not enough history yet - a shop that installed this week has
            // nothing honest to compare against.
            return response()->json(['has_data' => false]);
        }

        $baselineScore = $weekAgoRun->audit->score;
        $currentScore = $latestRun->audit->score;

        $diff = $diffService->diff(
            $weekAgoRun->audit->load('appImpacts', 'issues', 'pages'),
            $latestRun->audit->load('appImpacts', 'issues', 'pages'),
        );

        return response()->json([
            'has_data' => true,
            'baseline_score' => $baselineScore,
            'current_score' => $currentScore,
            'delta' => ($baselineScore !== null && $currentScore !== null) ? $currentScore - $baselineScore : null,
            'baseline_run_at' => $weekAgoRun->run_at,
            'diff' => $diff,
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
