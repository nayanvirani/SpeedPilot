<?php

namespace App\Services\Monitoring;

use App\Models\Audit;
use App\Models\MonitoringRun;
use App\Models\ShopInstallation;
use App\Services\PlanPolicy;
use App\Services\SlackNotifier;

/**
 * Records a MonitoringRun (trend delta, diff, regression/recovery alerts)
 * for any completed audit - extracted from RunMonitoringJob, which used to
 * be the only path that ever created one. That meant a shop only got a
 * "Performance health" reading once the daily scheduled cron happened to
 * run for it (confirmed live: a shop with 4 real completed manual scans
 * still showed "Not yet monitored", since none of them were triggered by
 * that schedule). Every completed audit records here now, regardless of
 * what triggered it - RunAuditJob calls this directly, and
 * RunMonitoringJob's own scheduled scan goes through the exact same
 * RunAuditJob path, so this is the one place monitoring data is ever
 * written.
 */
class MonitoringRecorder
{
    // A drop this small is normal run-to-run Lighthouse noise, not a real
    // regression worth interrupting a merchant over.
    private const REGRESSION_THRESHOLD = -5;

    // Tolerance for "recovered": don't require the score to land back on the
    // exact pre-regression integer - lab-metric noise means back-to-back
    // scans of an unchanged page can differ by a point or two either way.
    private const RECOVERY_TOLERANCE = 2;

    public function __construct(
        private readonly AuditDiffService $diffService,
        private readonly SlackNotifier $slack,
    ) {
    }

    public function record(ShopInstallation $shop, Audit $audit): void
    {
        if (! (new PlanPolicy($shop))->monitoringLevel() || ! $shop->isActive()) {
            return;
        }

        if (! $audit->isComplete()) {
            return; // a failed scan has nothing worth recording
        }

        // Idempotent - RunMonitoringJob's own scheduled scan and this
        // method's direct call from RunAuditJob would otherwise double up
        // for the exact same audit.
        if ($shop->monitoringRuns()->where('audit_id', $audit->id)->exists()) {
            return;
        }

        $previousAudit = $shop->audits()
            ->where('status', 'complete')
            ->where('id', '!=', $audit->id)
            ->where('created_at', '<', $audit->created_at)
            ->latest('created_at')
            ->first();
        $previousScore = $previousAudit?->score;

        $trendDelta = $previousScore !== null && $audit->score !== null
            ? $audit->score - $previousScore
            : null;

        $isRegression = $trendDelta !== null && $trendDelta <= self::REGRESSION_THRESHOLD;

        $diff = ($previousAudit && $previousAudit->isComplete())
            ? $this->diffService->diff(
                $previousAudit->load('appImpacts', 'issues', 'pages'),
                $audit->load('appImpacts', 'issues', 'pages'),
            )
            : null;

        // Needed for the recovery check below - must read before creating
        // this run, or it would just find itself.
        $lastRun = $shop->monitoringRuns()->latest('run_at')->first();

        $shop->monitoringRuns()->create([
            'audit_id' => $audit->id,
            'run_at' => now(),
            'trend_delta' => $trendDelta,
            'is_regression' => $isRegression,
            'diff_summary' => $diff,
        ]);

        if ($isRegression) {
            $this->slack->send($shop, $this->buildRegressionMessage($shop, $audit, $previousScore, $trendDelta, $diff ?? []));

            return;
        }

        // Only worth a "recovered" ping if the *previous* run was itself
        // flagged as a regression - i.e. there's something open to resolve.
        // Deliberately silent on every day a regressed score just plateaus
        // (no repeated "still down" spam) - only the initial drop and the
        // eventual recovery get a Slack message.
        if ($lastRun && $lastRun->is_regression) {
            $this->maybeSendRecoveryMessage($shop, $lastRun, $audit);
        }
    }

    private function maybeSendRecoveryMessage(ShopInstallation $shop, MonitoringRun $lastRegressedRun, Audit $audit): void
    {
        if ($audit->score === null) {
            return;
        }

        // Baseline = the score right before the regression started, i.e.
        // the run immediately preceding the one that got flagged.
        $baselineRun = $shop->monitoringRuns()
            ->where('id', '<', $lastRegressedRun->id)
            ->with('audit:id,score')
            ->latest('id')
            ->first();

        $baselineScore = $baselineRun?->audit?->score;

        if ($baselineScore === null) {
            return; // no usable baseline (e.g. the regression happened on the very first-ever run) - stay silent rather than guess
        }

        if ($audit->score >= $baselineScore - self::RECOVERY_TOLERANCE) {
            $this->slack->send($shop, sprintf(
                ":white_check_mark: SpeedPilot: performance has recovered on %s - score is back to %d (was %d before the regression). No action needed.",
                $shop->shop_domain,
                $audit->score,
                $baselineScore,
            ));
        }
    }

    private function buildRegressionMessage(ShopInstallation $shop, Audit $audit, int $previousScore, int $trendDelta, array $diff): string
    {
        $lines = [sprintf(
            ':warning: *SpeedPilot regression detected* on %s - score dropped from %d to %d (%d points).',
            $shop->shop_domain,
            $previousScore,
            $audit->score,
            $trendDelta,
        )];

        if (! empty($diff['new_third_party_scripts'])) {
            $names = collect($diff['new_third_party_scripts'])->pluck('app_name')->implode(', ');
            $lines[] = "- New third-party script(s) since the last scan: {$names} - a possible contributor, not a confirmed cause.";
        }

        if (! empty($diff['new_issues'])) {
            $count = count($diff['new_issues']);
            $titles = collect($diff['new_issues'])->take(3)->pluck('title')->implode('; ');
            $lines[] = "- {$count} new issue(s): {$titles}".($count > 3 ? '...' : '');
        }

        $worstPage = collect($diff['page_type_deltas'] ?? [])
            ->filter(fn ($p) => $p['delta'] !== null && $p['delta'] < 0)
            ->sortBy('delta')
            ->first();

        if ($worstPage) {
            $lines[] = sprintf(
                '- Biggest page-level drop: %s (%d -> %d).',
                ucfirst($worstPage['page_type']),
                $worstPage['previous_score'],
                $worstPage['current_score'],
            );
        }

        $lines[] = 'See the Monitoring page for the full breakdown.';

        return implode("\n", $lines);
    }
}
