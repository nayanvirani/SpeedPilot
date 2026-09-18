<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Public RUM ingestion is real storefront visitor traffic with no auth -
        // throttle per shop_domain rather than per-IP so one busy store can't
        // starve another, and one visitor can't flood a single store's data.
        RateLimiter::for('rum', function ($request) {
            return Limit::perMinute(120)->by($request->input('shop_domain', $request->ip()));
        });
    }
}
