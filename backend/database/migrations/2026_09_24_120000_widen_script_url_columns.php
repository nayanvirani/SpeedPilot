<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A real scan (Facebook Pixel's "extended matching" query param - hundreds
 * of comma-separated numbers) produced a script_url well past varchar(255),
 * throwing a hard Postgres error mid-scan and killing the whole audit.
 * scanner/lib/thirdPartyAnalyzer.js now strips query strings at capture
 * time (they're runtime-generated anyway, never stable across scans or
 * present in a theme file), which fixes the common case - this widens the
 * column as defense-in-depth for anything with a genuinely long path.
 *
 * Raw SQL rather than Schema::table()->change() - this app doesn't have
 * doctrine/dbal installed (nothing else in these migrations uses ->change()
 * either), and Postgres's ALTER COLUMN TYPE is a one-line statement anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE app_impacts ALTER COLUMN script_url TYPE text');
        DB::statement('ALTER TABLE interceptor_delay_targets ALTER COLUMN script_url TYPE text');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE app_impacts ALTER COLUMN script_url TYPE varchar(255)');
        DB::statement('ALTER TABLE interceptor_delay_targets ALTER COLUMN script_url TYPE varchar(255)');
    }
};
