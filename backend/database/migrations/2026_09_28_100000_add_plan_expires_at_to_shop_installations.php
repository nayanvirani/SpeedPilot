<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fast-path cache of the current (or most recently active) Subscription's
 * current_period_end - mirrors how `plan` itself is already cached here
 * from the Subscription history table, so every request doesn't need a
 * join to check access. Deliberately NOT cleared when a subscription is
 * cancelled - ShopInstallation::hasPlanAccess() compares against "now" at
 * read time, which is what lets a cancelled-but-not-yet-expired shop keep
 * access through what it already paid for, then lose it automatically the
 * moment this date passes with no further action needed anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->timestamp('plan_expires_at')->nullable()->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->dropColumn('plan_expires_at');
        });
    }
};
