<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_pages', function (Blueprint $table) {
            // Per-page-type trend queries filter by page_type (and device) first,
            // then join back to audits by audit_id - this ordering matches that
            // access pattern.
            $table->index(['page_type', 'device', 'audit_id'], 'audit_pages_type_device_audit_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_pages', function (Blueprint $table) {
            $table->dropIndex('audit_pages_type_device_audit_idx');
        });
    }
};
