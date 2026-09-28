<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            // When set alongside `url`, RunAuditJob uses this as the single
            // page's real type instead of defaulting to 'custom' - needed
            // for the verification audit ApplySafeFixesJob creates against a
            // duplicate theme's preview URL, which is genuinely re-checking
            // the homepage, not a merchant-chosen spot-check URL. Null keeps
            // today's behavior (a merchant-supplied `url` really is a custom
            // spot-check).
            $table->string('url_page_type')->nullable()->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            $table->dropColumn('url_page_type');
        });
    }
};
