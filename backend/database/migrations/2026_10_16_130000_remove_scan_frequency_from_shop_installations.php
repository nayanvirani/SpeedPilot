<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Daily automatic monitoring re-scans are gone - a full Playwright+
 * Lighthouse run per shop per day was real, unsustainable scanner compute
 * cost. Automatic monitoring is weekly for every shop now, unconditionally
 * (see RunMonitoringJob), so there's no longer a per-shop frequency choice
 * for this column to store - dropped rather than left around unused.
 * Manual "Scan My Store" scans are a separate, still-real limit
 * (PlanPolicy::manualScanCooldownDays() - 1/day paid, 1/week free), unaffected
 * by this column either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->dropColumn('scan_frequency');
        });

        // Starter's top_features (shown identically on the Partner Dashboard,
        // in-app billing, and the landing page per Plan::top_features) still
        // said "Daily monitoring" - no longer true, fixed here rather than
        // left to drift out of sync with what the app and Shopify's listing
        // actually offer.
        $starter = DB::table('plans')->where('key', 'starter')->first();

        if ($starter && $starter->top_features) {
            $features = array_map(
                fn (string $f) => $f === 'Daily monitoring & alerts' ? 'Weekly monitoring & alerts' : $f,
                json_decode($starter->top_features, true) ?? [],
            );

            DB::table('plans')->where('key', 'starter')->update(['top_features' => json_encode($features)]);
        }
    }

    public function down(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->string('scan_frequency')->default('weekly');
        });
    }
};
