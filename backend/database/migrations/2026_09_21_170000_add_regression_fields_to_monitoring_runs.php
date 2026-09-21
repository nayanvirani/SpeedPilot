<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_runs', function (Blueprint $table) {
            $table->boolean('is_regression')->default(false)->after('trend_delta');
            // The full diff computed once at run time (score/metric/weight deltas,
            // new/removed third-party scripts, new/resolved issues, per-page-type
            // deltas) - stored so historical runs and the monthly report never need
            // to recompute it against audits that may since have aged out.
            $table->json('diff_summary')->nullable()->after('is_regression');
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_runs', function (Blueprint $table) {
            $table->dropColumn(['is_regression', 'diff_summary']);
        });
    }
};
