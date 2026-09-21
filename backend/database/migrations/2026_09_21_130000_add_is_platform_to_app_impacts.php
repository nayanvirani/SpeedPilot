<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_impacts', function (Blueprint $table) {
            // Shopify's own platform scripts (Shop Pay, shop-js analytics,
            // checkout) get attributed to a "Shopify"/"shop.app" entity by
            // Lighthouse's third-party classification, same as any real
            // installed app - but they're injected by Shopify itself via
            // content_for_header, never present as literal text anywhere in
            // the theme's own files, and aren't something any app (including
            // this one) can or should disable/delay. Disable/Delay/Manual
            // fix are hidden for these rows instead of always failing.
            $table->boolean('is_platform')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('app_impacts', function (Blueprint $table) {
            $table->dropColumn('is_platform');
        });
    }
};
