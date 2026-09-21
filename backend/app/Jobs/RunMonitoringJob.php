<?php

namespace App\Jobs;

use App\Models\Audit;
use App\Models\MonitoringRun;
use App\Models\ShopInstallation;
use App\Services\Monitoring\AuditDiffService;
use App\Services\PlanPolicy;
use App\Services\Scanner\StorefrontAccessChecker;
use App\Services\SlackNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Scheduled daily/weekly re-audit + trend tracking, and the core of
 * SpeedPilot's recurring value: not just "did the score change" but "what
 * actually changed" (new third-party script, new issue, which page got
 * worse) - and, once a regression resolves, a follow-up "recovered" ping.
 * Dispatched by routes/console.php's schedule for every active,
 * monitoring-eligible shop.
 */
class RunMonitoringJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $shopInstallationId)
    {
    }

    // A drop this small is normal run-to-run Lighthouse noise, not a real
    // regression worth interrupting a merchant over.
    private const REGRESSION_THRESHOLD = -5;

    // Tolerance for "recovered": don't require the score to land back on the
    // exact pre-regression integer - lab-metric noise means back-to-back
    // scans of an unchanged page can differ by a point or two either way.
    private const RECOVERY_TOLERANCE = 2;

    public function handle(SlackNotifier $slack, StorefrontAccessChecker $storefrontAccess, AuditDiffService $diffService): void
    {
        $shop = ShopInstallation::findOrFail($this->shopInstallationId);
        $policy = new PlanPolicy($shop);

        if (! $policy->monitoringLevel() || ! $shop->isActive()) {
            return;
        }

        if ($storefrontAccess->blocksScan($shop)) {
            return; // same doomed-scan case as the manual/auto-install paths - just skip this cycle
        }

        $lastRun = $shop->monitoringRuns()->latest('run_at')->first();

        // The scheduler ticks daily for every shop regardless of
        // preference - a "weekly" shop just skips most of those ticks here,
        // rather than needing its own per-shop cron entry.
        if ($shop->scan_frequency === 'weekly' && $lastRun && $lastRun->run_at->diffInDays(now()) < 7) {
            return;
        }

        // Must capture the actual previous Audit model (not just its score)
        // before creating the new one - latestAudit() would otherwise return
        // the audit this run is about to create, once it exists.
        $previousAudit = $shop->latestAudit();
        $previousScore = $previousAudit?->score;

        // url stays null so this covers every page the plan allows, not
        // just the homepage - matches what "Scan My Store" now does.
        $audit = $shop->audits()->create(['status' => 'pending']);

        RunAuditJob::dispatchSync($audit->id);

        $audit->refresh();

        $trendDelta = $previousScore !== null && $audit->score !== null
            ? $audit->score - $previousScore
            : null;

        $isRegression = $trendDelta !== null && $trendDelta <= self::REGRESSION_THRESHOLD;

        // Only diff two complete audits - a failed re-scan has no
        // metrics/issues/appImpacts worth comparing, and produces the same
        // "nothing to diff" outcome as a genuine first-ever run.
        $diff = ($previousAudit && $previousAudit->isComplete() && $audit->isComplete())
            ? $diffService->diff(
                $previousAudit->load('appImpacts', 'issues', 'pages'),
                $audit->load('appImpacts', 'issues', 'pages'),
            )
            : null;

        $shop->monitoringRuns()->create([
            'audit_id' => $audit->id,
            'run_at' => now(),
            'trend_delta' => $trendDelta,
            'is_regression' => $isRegression,
            'diff_summary' => $diff,
        ]);

        if ($isRegression) {
            $slack->send($shop, $this->buildRegressionMessage($shop, $audit, $previousScore, $trendDelta, $diff ?? []));

            return;
        }

        // Only worth a "recovered" ping if the *previous* run was itself
        // flagged as a regression - i.e. there's something open to resolve.
        // Deliberately silent on every day a regressed score just plateaus
        // (no repeated "still down" spam) - only the initial drop and the
        // eventual recovery get a Slack message.
        if ($lastRun && $lastRun->is_regression) {
            $this->maybeSendRecoveryMessage($shop, $slack, $lastRun, $audit);
        }
    }

    private function maybeSendRecoveryMessage(ShopInstallation $shop, SlackNotifier $slack, MonitoringRun $lastRegressedRun, Audit $audit): void
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
            $slack->send($shop, sprintf(
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
