<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persistent, independent of any one audit's app_impacts rows - those are
 * recreated fresh on every scan, so a merchant's "advanced delay" choice for
 * a script SpeedPilot can't edit in theme files (Shopify ScriptTag-injected)
 * needs its own durable record, or it would silently reset to "Active" the
 * next time a scan runs. This table is the single source of truth for what
 * the theme.liquid interceptor snippet currently watches for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interceptor_delay_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_installation_id')->constrained()->cascadeOnDelete();
            $table->string('script_url');
            $table->timestamps();

            $table->unique(['shop_installation_id', 'script_url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interceptor_delay_targets');
    }
};
