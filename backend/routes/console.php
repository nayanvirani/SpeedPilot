<?php

use App\Jobs\RunMonitoringJob;
use App\Models\ShopInstallation;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 3 monitoring: daily re-audit for every active, still-installed shop.
// Growth/Pro get "advanced" monitoring (still daily here - the cadence
// difference in the spec is about what's surfaced, not scan frequency);
// PlanPolicy inside RunMonitoringJob skips shops without monitoring enabled.
Schedule::call(function () {
    ShopInstallation::whereNull('uninstalled_at')->each(
        fn (ShopInstallation $shop) => RunMonitoringJob::dispatch($shop->id)
    );
})->daily()->name('speedpilot:monitoring')->withoutOverlapping();
