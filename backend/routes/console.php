<?php

use App\Jobs\RefreshExpiringAccessTokensJob;
use App\Jobs\RunMonitoringJob;
use App\Models\ShopInstallation;
use App\Services\Monitoring\MonthlyReportService;
use App\Services\PlanPolicy;
use App\Services\SlackNotifier;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// An offline access token only lasts 1 hour - refresh it well before that,
// using the stored refresh_token (no merchant session needed), so every
// other background job below (and any queued job in between) never hits a
// dead token just because nobody happened to open the embedded app
// recently. A 20-minute lookahead against a 15-minute tick always leaves
// margin before the real 60-minute expiry.
Schedule::call(function () {
    ShopInstallation::whereNull('uninstalled_at')
        ->whereNotNull('refresh_token')
        ->where(function ($q) {
            $q->whereNull('access_token_expires_at')
                ->orWhere('access_token_expires_at', '<', now()->addMinutes(20));
        })
        ->each(fn (ShopInstallation $shop) => RefreshExpiringAccessTokensJob::dispatch($shop->id));
})->everyFifteenMinutes()->name('speedpilot:refresh-offline-tokens')->withoutOverlapping();

// Phase 3 monitoring: daily re-audit for every active, still-installed shop.
// Growth/Pro get "advanced" monitoring (still daily here - the cadence
// difference in the spec is about what's surfaced, not scan frequency);
// PlanPolicy inside RunMonitoringJob skips shops without monitoring enabled.
Schedule::call(function () {
    ShopInstallation::whereNull('uninstalled_at')->each(
        fn (ShopInstallation $shop) => RunMonitoringJob::dispatch($shop->id)
    );
})->daily()->name('speedpilot:monitoring')->withoutOverlapping();

// The recurring proof point that keeps the subscription justified after the
// initial optimization is done: one Slack digest per shop for the month
// that just ended. No email capability exists yet, so this - plus the
// in-app /monitoring/report - is the "monthly report" for now.
Schedule::call(function (MonthlyReportService $reports, SlackNotifier $slack) {
    $previousMonth = now()->subMonthNoOverflow()->format('Y-m');

    ShopInstallation::whereNull('uninstalled_at')->each(function (ShopInstallation $shop) use ($reports, $slack, $previousMonth) {
        if (! (new PlanPolicy($shop))->monitoringLevel()) {
            return;
        }

        $report = $reports->build($shop, $previousMonth);

        if (! $report['has_data']) {
            return;
        }

        $slack->send($shop, sprintf(
            ":bar_chart: *SpeedPilot monthly report - %s*\nScore: %d -> %d (%+d). %d issue(s) resolved, %d new. "
                .'%d regression(s) detected, %d optimization(s) applied automatically.'
                ."\nFull report: check the Monitoring page in SpeedPilot.",
            $previousMonth,
            $report['score']['before'],
            $report['score']['after'],
            $report['score']['delta'] ?? 0,
            count($report['issues_resolved']),
            count($report['issues_new']),
            $report['regressions']->count(),
            $report['optimizations_applied']->count(),
        ));
    });
})->monthlyOn(1, '09:00')->name('speedpilot:monthly-digest')->withoutOverlapping();
