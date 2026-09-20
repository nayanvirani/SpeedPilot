<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            // Null until the merchant explicitly picks one - ApplySafeFixesJob
            // and ScriptImpactActionService refuse to write anywhere until
            // this is set, rather than silently defaulting to the live theme
            // the way every theme-writing action did before this existed.
            $table->string('target_theme_id')->nullable()->after('storefront_password');
            $table->string('target_theme_mode')->nullable()->after('target_theme_id'); // 'live' | 'duplicate'
        });
    }

    public function down(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->dropColumn(['target_theme_id', 'target_theme_mode']);
        });
    }
};
