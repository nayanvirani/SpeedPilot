<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Smart Script Manager, extended to theme-owned JS/CSS (not just
 * third-party apps) - "fetch theme JS and CSS and make delay options", the
 * gap identified auditing jewel-nests' real score (its own theme assets,
 * not just installed apps, carry real render-blocking/unused weight).
 *
 * A theme-owned rule is keyed by `asset_identifier` (a normalized
 * basename, e.g. "swiper-bundle.min.js" with any content hash/version
 * stripped - see ScannerClient-side normalizedBasename()) rather than
 * `app_name`, for the exact same reason app_name was chosen over
 * script_url originally (see this table's own creation migration): a
 * theme asset's rendered CDN URL carries a fingerprint that rotates on
 * every theme deploy, so only the stable basename survives across scans.
 * `target_type` disambiguates which column (app_name vs asset_identifier)
 * a given row is actually keyed by.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('script_page_rules', function (Blueprint $table) {
            $table->string('target_type')->default('app')->after('shop_installation_id'); // app | theme_asset
            $table->string('asset_identifier')->nullable()->after('app_name');
        });

        DB::statement('ALTER TABLE script_page_rules ALTER COLUMN app_name DROP NOT NULL');

        // The original unique() call named its backing index/constraint
        // the standard Laravel way, but that name is 67 characters -
        // Postgres silently truncates identifiers past 63
        // (NAMEDATALEN-1), so the real stored name isn't the literal
        // string Laravel generated. Looking it up live rather than
        // guessing the truncation avoids exactly that mismatch (hit once
        // already - DROP INDEX with the untruncated name both named the
        // wrong object AND can't drop a constraint-backed index directly
        // regardless). ALTER TABLE ... DROP CONSTRAINT is also the only
        // form Postgres accepts for a constraint-backed unique index.
        $constraint = DB::selectOne(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'script_page_rules'::regclass AND contype = 'u'"
        );

        if ($constraint) {
            DB::statement('ALTER TABLE script_page_rules DROP CONSTRAINT "'.$constraint->conname.'"');
        }

        DB::statement(
            'CREATE UNIQUE INDEX script_page_rules_target_unique ON script_page_rules '
            .'(shop_installation_id, target_type, COALESCE(app_name, \'\'), COALESCE(asset_identifier, \'\'), page_type)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS script_page_rules_target_unique');
        DB::statement("DELETE FROM script_page_rules WHERE target_type != 'app'");
        DB::statement('ALTER TABLE script_page_rules ALTER COLUMN app_name SET NOT NULL');

        Schema::table('script_page_rules', function (Blueprint $table) {
            $table->dropColumn(['target_type', 'asset_identifier']);
            $table->unique(['shop_installation_id', 'app_name', 'page_type']);
        });
    }
};
