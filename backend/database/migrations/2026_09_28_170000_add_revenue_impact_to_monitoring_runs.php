<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_runs', function (Blueprint $table) {
            // Same shape RevenueImpactEstimator::estimate() already returns,
            // computed from consecutive-run LCP deltas (not first-vs-latest)
            // so summing this column across every run gives a real running
            // total since monitoring began, not one snapshot - the "ROI
            // ledger" on the Monitoring page.
            $table->json('revenue_impact')->nullable()->after('diff_summary');
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_runs', function (Blueprint $table) {
            $table->dropColumn('revenue_impact');
        });
    }
};
