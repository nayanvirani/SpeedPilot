<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single app can load more than one distinct file - Lighthouse groups
 * every request under one "entity", but only the single biggest one
 * (by transferSize) ever became this row's script_url, silently dropping
 * every other file the same app also loads (confirmed live: Judge.me ships
 * both cdn.judge.me/reviews/... AND cdn.shopify.com/extensions/{uuid}/
 * judgeme-762/assets/carousels.js in the same scan). Turning on "Advanced
 * delay" needs to watch all of them, not just whichever happened to be
 * biggest this run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_impacts', function (Blueprint $table) {
            $table->json('related_script_urls')->nullable()->after('script_url');
        });
    }

    public function down(): void
    {
        Schema::table('app_impacts', function (Blueprint $table) {
            $table->dropColumn('related_script_urls');
        });
    }
};
