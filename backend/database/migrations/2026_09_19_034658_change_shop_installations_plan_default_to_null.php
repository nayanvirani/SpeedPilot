<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * There is no billable "Free" plan (see config/speedpilot.php) - a shop
 * without an active subscription now has plan = null rather than the
 * string 'free', so PlanPolicy's "unrecognized plan" fallback covers it
 * naturally instead of pointing at a config key that no longer exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE shop_installations ALTER COLUMN plan DROP DEFAULT');
        DB::statement('ALTER TABLE shop_installations ALTER COLUMN plan DROP NOT NULL');
        DB::statement("UPDATE shop_installations SET plan = NULL WHERE plan = 'free'");
    }

    public function down(): void
    {
        DB::statement("UPDATE shop_installations SET plan = 'free' WHERE plan IS NULL");
        DB::statement("ALTER TABLE shop_installations ALTER COLUMN plan SET DEFAULT 'free'");
        DB::statement('ALTER TABLE shop_installations ALTER COLUMN plan SET NOT NULL');
    }
};
