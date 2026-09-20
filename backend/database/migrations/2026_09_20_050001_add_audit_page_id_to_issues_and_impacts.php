<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nullable and additive: audit_id stays the source of truth for
        // "every issue/impact this audit found" (unchanged queries keep
        // working); audit_page_id only narrows to which page it came from,
        // for the new per-page breakdown. App/script impacts are merged
        // across pages before being saved (see RunAuditJob), so their
        // audit_page_id is always null - only audit_issues gets it set.
        Schema::table('audit_issues', function (Blueprint $table) {
            $table->foreignId('audit_page_id')->nullable()->after('audit_id')->constrained()->cascadeOnDelete();
        });

        Schema::table('app_impacts', function (Blueprint $table) {
            $table->foreignId('audit_page_id')->nullable()->after('audit_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_issues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('audit_page_id');
        });

        Schema::table('app_impacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('audit_page_id');
        });
    }
};
