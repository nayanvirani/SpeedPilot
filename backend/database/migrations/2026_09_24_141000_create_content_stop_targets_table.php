<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors interceptor_delay_targets - durable, independent of any one
 * audit's ephemeral app_impacts rows, since a merchant's "Stop (verified)"
 * choice needs its own record or it would silently reset to "Active" the
 * next time a scan runs. source_snippet is copied in at the moment the
 * merchant turns this on (not re-read live from app_impacts on every
 * rewrite) because hashed asset filenames rotate between scans - a stale
 * snippet just makes the Liquid `replace` a safe no-op, never an error, but
 * RunAuditJob refreshes it here whenever a later scan sees a different-but-
 * still-present match so a live "stop" doesn't silently go stale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_stop_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_installation_id')->constrained()->cascadeOnDelete();
            $table->string('script_url');
            $table->text('source_snippet');
            $table->timestamps();

            $table->unique(['shop_installation_id', 'script_url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_stop_targets');
    }
};
