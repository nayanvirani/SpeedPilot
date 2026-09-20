<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            // Set whenever Shopify's GraphQL API rejects a theme write with
            // ACCESS_DENIED (write_themes scope granted, but the protected-
            // scope exemption isn't approved yet) - lets the UI say "waiting
            // on Shopify" honestly instead of silently doing nothing.
            $table->timestamp('theme_write_blocked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->dropColumn('theme_write_blocked_at');
        });
    }
};
