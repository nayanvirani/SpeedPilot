<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            // Set whenever a scan was refused (or a reactive mid-scan check
            // confirmed) the storefront is password-protected with no
            // storefront_password saved - lets the Dashboard show a clear,
            // upfront prompt instead of ever displaying a "failed" audit
            // with no scores for this entirely avoidable case.
            $table->timestamp('storefront_locked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->dropColumn('storefront_locked_at');
        });
    }
};
