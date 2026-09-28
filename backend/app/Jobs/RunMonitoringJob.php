<?php

namespace App\Jobs;

use App\Models\ShopInstallation;
use App\Services\Monitoring\MonitoringRecorder;
use App\Services\PlanPolicy;
use App\Services\Scanner\StorefrontAccessChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Decides whether today's scheduled re-scan should happen for this shop
 * (monitoring-eligible, not already mid-scan, weekly shops skipping most
 * daily ticks) and triggers it. Recording the actual trend/diff/regression
 * data is MonitoringRecorder's job, not this one - RunAuditJob calls it
 * directly once the scan this job triggers completes, the same as it does
 * for a manual "Scan My Store" click, so monitoring data updates from
 * every completed scan, not only ones this schedule happened to trigger.
 */
class RunMonitoringJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $shopInstallationId)
    {
    }

    public function handle(StorefrontAccessChecker $storefrontAccess): void
    {
        $shop = ShopInstallation::findOrFail($this->shopInstallationId);
        $policy = new PlanPolicy($shop);

        if (! $policy->monitoringLevel() || ! $shop->isActive()) {
            return;
        }

        if ($storefrontAccess->blocksScan($shop)) {
            return; // same doomed-scan case as the manual/auto-install paths - just skip this cycle
        }

        if ($shop->hasAuditInProgress()) {
            return; // a manual scan or another trigger is already running - this cycle just skips, tomorrow's tick will catch up
        }

        $lastRun = $shop->monitoringRuns()->latest('run_at')->first();

        // The scheduler ticks daily for every shop regardless of
        // preference - a "weekly" shop just skips most of those ticks here,
        // rather than needing its own per-shop cron entry.
        if ($shop->scan_frequency === 'weekly' && $lastRun && $lastRun->run_at->diffInDays(now()) < 7) {
            return;
        }

        // url stays null so this covers every page the plan allows, not
        // just the homepage - matches what "Scan My Store" now does.
        // RunAuditJob records the MonitoringRun (trend/diff/alerts) itself
        // once this completes - see MonitoringRecorder.
        $audit = $shop->audits()->create(['status' => 'pending']);

        RunAuditJob::dispatchSync($audit->id);
    }
}
