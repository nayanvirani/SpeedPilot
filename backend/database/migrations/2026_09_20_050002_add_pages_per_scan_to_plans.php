<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedTinyInteger('pages_per_scan')->default(1)->after('history_days');
        });

        // Starter keeps the original single-page (homepage-only) scan;
        // Pro's key differentiator is auditing home + a product + a
        // collection page in one run, since real slowdowns are often on
        // pages the homepage-only scan never looked at.
        DB::table('plans')->where('key', 'pro')->update(['pages_per_scan' => 3]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('pages_per_scan');
        });
    }
};
