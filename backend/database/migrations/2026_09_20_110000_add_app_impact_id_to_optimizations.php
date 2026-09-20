<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('optimizations', function (Blueprint $table) {
            // Disable/delay actions from the App & Script Impact page aren't
            // tied to a specific audit_issue - they're triggered directly
            // from an AppImpact row, and need their own link back to it so
            // re-enabling can find what to roll back.
            $table->foreignId('app_impact_id')->nullable()->after('audit_issue_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('optimizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('app_impact_id');
        });
    }
};
