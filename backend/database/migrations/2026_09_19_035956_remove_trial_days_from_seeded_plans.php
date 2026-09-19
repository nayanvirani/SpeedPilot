<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * No free trial on either plan - billing starts immediately on subscribe.
 * This only touches the two seeded rows' trial_days; the column itself
 * stays (an admin can still set a trial per-plan later via /admin/plans).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('plans')->whereIn('key', ['starter', 'pro'])->update(['trial_days' => 0]);
    }

    public function down(): void
    {
        DB::table('plans')->whereIn('key', ['starter', 'pro'])->update(['trial_days' => 7]);
    }
};
