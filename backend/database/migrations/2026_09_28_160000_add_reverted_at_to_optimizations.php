<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('optimizations', function (Blueprint $table) {
            // Set by OptimizationDriftChecker when an 'applied' fix's live
            // content no longer matches what was written (a theme
            // republish, or a manual edit reverting it) - distinct from
            // status='rolled_back', which only ever means the merchant
            // clicked Rollback themselves. Keeping status untouched means
            // every existing status='applied' query elsewhere in the app
            // keeps its current meaning; this is purely additive.
            $table->timestamp('reverted_at')->nullable()->after('applied_at');
        });
    }

    public function down(): void
    {
        Schema::table('optimizations', function (Blueprint $table) {
            $table->dropColumn('reverted_at');
        });
    }
};
