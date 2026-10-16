<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets AuditController::nextManualScanAt() tell "the last scan is stale
 * because the target theme changed since" apart from "the merchant is just
 * re-scanning the same thing too often" - switching target themes means
 * every existing scan's data (App & Script Impact, Optimizations, the
 * Dashboard score) reflects the OLD theme, so the merchant genuinely needs
 * a fresh scan, not a cooldown built for a different scenario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->timestamp('target_theme_changed_at')->nullable()->after('target_theme_mode');
        });
    }

    public function down(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->dropColumn('target_theme_changed_at');
        });
    }
};
