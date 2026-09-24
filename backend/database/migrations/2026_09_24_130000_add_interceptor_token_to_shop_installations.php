<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identifies a shop to the public /storefront/interceptor.js endpoint - an
 * opaque token rather than the raw shop domain, so a storefront visitor (or
 * anyone) can't enumerate other shops' delay lists by guessing domains.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->string('interceptor_token')->nullable()->unique()->after('storefront_locked_at');
        });
    }

    public function down(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->dropColumn('interceptor_token');
        });
    }
};
