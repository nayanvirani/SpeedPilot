<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            // Set when this audit is an automatic follow-up re-scan after
            // ApplySafeFixesJob applied something - closes the spec's
            // "validate/re-scan after applying changes" safety rule with a
            // real before/after pair instead of just trusting the fix worked.
            $table->foreignId('verifies_audit_id')->nullable()->after('shop_installation_id')
                ->constrained('audits')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verifies_audit_id');
        });
    }
};
