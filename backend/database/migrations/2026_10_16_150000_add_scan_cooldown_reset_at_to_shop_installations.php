<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a super admin unblock one store's manual-scan cooldown on demand
 * (support requests, active debugging sessions) without touching audit
 * history - AuditController::nextManualScanAt() already has this exact
 * pattern for target_theme_changed_at: a tracked timestamp that, when more
 * recent than the last complete audit, means that audit shouldn't count
 * against the cooldown. Same mechanism, a different trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->timestamp('scan_cooldown_reset_at')->nullable()->after('target_theme_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->dropColumn('scan_cooldown_reset_at');
        });
    }
};
