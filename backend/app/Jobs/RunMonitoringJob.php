<?php

namespace App\Jobs;

use App\Models\ShopInstallation;
use App\Services\PlanPolicy;
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

    public function handle(): void
    {
        $shop = ShopInstallation::findOrFail($this->shopInstallationId);
        $policy = new PlanPolicy($shop);

        if (! $policy->monitoringLevel() || ! $shop->isActive()) {
            return;
        }

        $previousScore = $shop->latestAudit()?->score;
        $url = 'https://'.$shop->shop_domain;

        $audit = $shop->audits()->create(['url' => $url, 'status' => 'pending']);

        RunAuditJob::dispatchSync($audit->id);

        $audit->refresh();

        $shop->monitoringRuns()->create([
            'audit_id' => $audit->id,
            'run_at' => now(),
            'trend_delta' => $previousScore !== null && $audit->score !== null
                ? $audit->score - $previousScore
                : null,
        ]);
    }
}
