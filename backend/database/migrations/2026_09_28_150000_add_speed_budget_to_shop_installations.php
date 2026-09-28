<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            // Null = no budget set (disabled). When set, MonitoringRecorder
            // alerts the merchant the moment a completed scan's LCP first
            // crosses this line, and again when it recovers -
            // speed_budget_breached_at tracks which state we're in so the
            // alert only fires on the transition, not every day it stays bad
            // (same pattern as the existing regression/recovery alerts).
            $table->decimal('speed_budget_lcp_seconds', 5, 2)->nullable();
            $table->timestamp('speed_budget_breached_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->dropColumn(['speed_budget_lcp_seconds', 'speed_budget_breached_at']);
        });
    }
};
