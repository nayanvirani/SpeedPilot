<?php

namespace App\Jobs;

use App\Models\ShopInstallation;
use App\Services\PlanPolicy;
use App\Services\SlackNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 3: scheduled daily/weekly re-audit + trend tracking. Dispatched by
 * app/Console/Kernel's schedule for every active, monitoring-eligible shop.
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

    public function handle(SlackNotifier $slack): void
    {
        $shop = ShopInstallation::findOrFail($this->shopInstallationId);
        $policy = new PlanPolicy($shop);

        if (! $policy->monitoringLevel() || ! $shop->isActive()) {
            return;
        }

        // The scheduler ticks daily for every shop regardless of
        // preference - a "weekly" shop just skips most of those ticks here,
        // rather than needing its own per-shop cron entry.
        if ($shop->scan_frequency === 'weekly') {
            $lastRun = $shop->monitoringRuns()->latest('run_at')->first();

            if ($lastRun && $lastRun->run_at->diffInDays(now()) < 7) {
                return;
            }
        }

        $previousScore = $shop->latestAudit()?->score;

        // url stays null so this covers every page the plan allows, not
        // just the homepage - matches what "Scan My Store" now does.
        $audit = $shop->audits()->create(['status' => 'pending']);

        RunAuditJob::dispatchSync($audit->id);

        $audit->refresh();

        $trendDelta = $previousScore !== null && $audit->score !== null
            ? $audit->score - $previousScore
            : null;

        $shop->monitoringRuns()->create([
            'audit_id' => $audit->id,
            'run_at' => now(),
            'trend_delta' => $trendDelta,
        ]);

        if ($trendDelta !== null && $trendDelta <= self::REGRESSION_THRESHOLD) {
            $slack->send($shop, sprintf(
                ':warning: SpeedPilot detected a performance regression on %s: score dropped from %d to %d (%d points). '
                    .'A new app, theme edit, or third-party script may be the cause - check the Monitoring page.',
                $shop->shop_domain,
                $previousScore,
                $audit->score,
                $trendDelta,
            ));
        }
    }
}
