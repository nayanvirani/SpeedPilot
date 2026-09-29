<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nullable - existing rows (and any visitor on a not-yet-updated cached
 * copy of rum.js) simply have no breakdown value, not a broken insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rum_events', function (Blueprint $table) {
            $table->string('device_type')->nullable()->after('page_url');
            $table->string('browser')->nullable()->after('device_type');
        });
    }

    public function down(): void
    {
        Schema::table('rum_events', function (Blueprint $table) {
            $table->dropColumn(['device_type', 'browser']);
        });
    }
};
