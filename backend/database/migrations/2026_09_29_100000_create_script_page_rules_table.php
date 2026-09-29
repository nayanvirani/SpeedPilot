<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smart Script Manager: per-app, per-page-type load control ("Chat widget:
 * Product pages -> on immediately, Collection -> after scroll, Homepage ->
 * off"), additive to the existing global interceptor_delay_targets list
 * (page_type = 'default' is this app's fallback rule across every page type
 * that has no more specific row - a literal sentinel rather than nullable,
 * since Postgres treats each NULL as distinct and would silently let more
 * than one "default" row past the unique constraint below). Keyed by
 * app_name rather than script_url
 * - hashed asset filenames rotate between scans (see
 * ScriptImpactActionService::interceptorDelay()'s docblock), so app_name is
 * the only stable identity across audits; the actual URLs to watch are
 * resolved fresh from the latest audit's app_impacts at serve time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('script_page_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_installation_id')->constrained()->cascadeOnDelete();
            $table->string('app_name');
            $table->string('page_type'); // a real page_type, or the literal 'default'
            $table->string('trigger'); // never | immediate | interaction | timeout | scroll
            $table->unsignedInteger('delay_seconds')->nullable();
            $table->timestamps();

            $table->unique(['shop_installation_id', 'app_name', 'page_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('script_page_rules');
    }
};
