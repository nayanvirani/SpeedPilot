<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_backups', function (Blueprint $table) {
            // original_content alone only shows "what it was" - capturing
            // the fixed version too at the moment it's written means the
            // before/after diff shown to merchants doesn't depend on
            // re-fetching the theme's current (possibly since-changed) state.
            $table->longText('updated_content')->nullable()->after('original_content');
        });
    }

    public function down(): void
    {
        Schema::table('asset_backups', function (Blueprint $table) {
            $table->dropColumn('updated_content');
        });
    }
};
