<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_impacts', function (Blueprint $table) {
            // 'theme_edit' (found in theme files, edited directly - the
            // guaranteed mechanism) or 'interceptor' (best-effort client-side
            // delay for scripts SpeedPilot can't find in theme files). Lets
            // the UI badge distinguish which guarantee level a "Delayed" row
            // is actually on, and tells restore() which teardown path to use.
            $table->string('delay_method')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('app_impacts', function (Blueprint $table) {
            $table->dropColumn('delay_method');
        });
    }
};
