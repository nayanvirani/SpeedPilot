<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            // 'daily' | 'weekly' - governs RunMonitoringJob's scheduled
            // re-scans only; an on-demand "Scan My Store" click always runs
            // immediately regardless of this setting.
            $table->string('scan_frequency')->default('daily');
            // 'both' | 'mobile' | 'desktop' - governs which device(s)
            // RunAuditJob scans, for merchants who only care about one.
            $table->string('scan_devices')->default('both');
        });
    }

    public function down(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->dropColumn(['scan_frequency', 'scan_devices']);
        });
    }
};
