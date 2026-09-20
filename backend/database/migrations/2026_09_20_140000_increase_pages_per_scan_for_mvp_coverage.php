<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PageDiscoveryService now always includes cart and search (universal
 * routes, no lookup needed) before falling back to product/collection/blog
 * candidates - at the previous limit of 3, cart+search alone filled the
 * budget and silently pushed product/collection out entirely. 6 covers
 * home + product + collection + cart + search + blog, matching the MVP
 * spec's full page list.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('plans')->where('key', 'starter')->update(['pages_per_scan' => 6]);
    }

    public function down(): void
    {
        DB::table('plans')->where('key', 'starter')->update(['pages_per_scan' => 3]);
    }
};
