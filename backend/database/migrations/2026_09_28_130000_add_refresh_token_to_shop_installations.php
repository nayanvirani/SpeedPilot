<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            // An expiring offline access token only lasts 1 hour - without
            // storing the refresh_token that comes with it, the only way
            // this app ever renewed one was a merchant opening the embedded
            // app (VerifyShopifySessionToken re-running token exchange). A
            // shop nobody has opened in over an hour then has no valid
            // token for any background job (scheduled scans, auto-fix,
            // webhook processing) until someone does. Shopify's refresh_token
            // grant renews it server-side with no merchant interaction at
            // all - see RefreshExpiringAccessTokensJob.
            $table->text('refresh_token')->nullable()->after('access_token_expires_at');
            $table->timestamp('refresh_token_expires_at')->nullable()->after('refresh_token');
        });
    }

    public function down(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->dropColumn(['refresh_token', 'refresh_token_expires_at']);
        });
    }
};
