<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            // Only set for stores that have Shopify's storefront password
            // gate on (any dev store by default) - without it, every scan
            // redirects to /password and audits nothing real. Encrypted like
            // access_token since it's a real credential.
            $table->text('storefront_password')->nullable()->after('access_token');
        });
    }

    public function down(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->dropColumn('storefront_password');
        });
    }
};
