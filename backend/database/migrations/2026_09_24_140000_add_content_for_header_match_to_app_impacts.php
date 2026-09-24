<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The exact `src="..."`/`href="..."` substring, byte for byte, found in this
 * shop's own rendered content_for_header for this app's script during the
 * scan that produced this row - null when no literal match was found (e.g.
 * the script is loaded via Shopify's sandboxed Web Pixels Manager, not
 * static markup). Recreated fresh every scan, same as is_platform - the
 * durable "this is currently stopped" state lives in content_stop_targets,
 * not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_impacts', function (Blueprint $table) {
            $table->text('content_for_header_match')->nullable()->after('is_platform');
        });
    }

    public function down(): void
    {
        Schema::table('app_impacts', function (Blueprint $table) {
            $table->dropColumn('content_for_header_match');
        });
    }
};
