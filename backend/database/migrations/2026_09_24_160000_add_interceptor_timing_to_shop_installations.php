<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Advanced delay (experimental)" hardcoded its release timing (5s timeout,
 * first interaction) - merchants have no way to see or change that. Exposed
 * as two settings: how long to wait, and what else (besides the timeout,
 * which always applies as a hard fallback) can release early.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->unsignedInteger('interceptor_delay_ms')->default(5000)->after('interceptor_token');
            // interaction (default: scroll/click/touch/key) | window_load |
            // document_load | timeout_only (no early trigger, just the delay)
            $table->string('interceptor_trigger')->default('interaction')->after('interceptor_delay_ms');
        });
    }

    public function down(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->dropColumn(['interceptor_delay_ms', 'interceptor_trigger']);
        });
    }
};
