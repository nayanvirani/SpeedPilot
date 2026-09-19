<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Expiring" offline access tokens (required now - see
 * ShopifyOAuthService::exchangeSessionTokenForOfflineToken) actually expire,
 * unlike the old permanent kind. Rather than implementing a separate
 * refresh_token flow, VerifyShopifySessionToken just re-runs Token Exchange
 * with the current request's (already-valid) session token when this is
 * past - simpler than tracking a second token type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->timestamp('access_token_expires_at')->nullable()->after('access_token');
        });

        // Force the one shop provisioned before the "expiring" fix to
        // re-provision on its next request - its stored token is the
        // rejected non-expiring kind.
        DB::table('shop_installations')->update(['access_token' => null]);
    }

    public function down(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->dropColumn('access_token_expires_at');
        });
    }
};
