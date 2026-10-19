<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Triggered by a real incident: jewel-nests' homepage scored a flat 0
 * because a (now-fixed) storefront bug corrupted that one Lighthouse run -
 * the audit's overall score averaged that corrupted run in with full
 * weight, same as every clean one, dragging the whole reported number down
 * with it. `anomalous` marks a page/device run RunAuditJob itself
 * distrusts (score=0, or Lighthouse's own runtimeError) after one automatic
 * retry still came back bad - excluded from the audit-level average so one
 * bad run can't single-handedly misrepresent the whole store, but kept
 * (not deleted) and shown on its own card so nothing is silently hidden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_pages', function (Blueprint $table) {
            $table->boolean('anomalous')->default(false)->after('status');
            $table->string('runtime_error')->nullable()->after('anomalous');
        });
    }

    public function down(): void
    {
        Schema::table('audit_pages', function (Blueprint $table) {
            $table->dropColumn(['anomalous', 'runtime_error']);
        });
    }
};
