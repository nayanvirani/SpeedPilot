<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Set when a background job (no live App Bridge session token available to
 * refresh via Token Exchange) hits AccessTokenExpiredException - cleared
 * automatically the next time VerifyShopifySessionToken sees this shop with
 * a fresh session token, since simply opening the embedded app is what
 * resolves this. Mirrors theme_write_blocked_at's pattern, but for a
 * self-resolving condition rather than an approval gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->timestamp('needs_reauth_at')->nullable()->after('theme_write_blocked_at');
        });
    }

    public function down(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->dropColumn('needs_reauth_at');
        });
    }
};
