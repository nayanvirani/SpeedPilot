<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_pages', function (Blueprint $table) {
            // A base64 JPEG data URI from Lighthouse's own final-screenshot
            // audit - small enough (mobile viewport) to store directly
            // rather than standing up separate file/CDN storage for it.
            $table->longText('screenshot')->nullable()->after('css_weight_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('audit_pages', function (Blueprint $table) {
            $table->dropColumn('screenshot');
        });
    }
};
