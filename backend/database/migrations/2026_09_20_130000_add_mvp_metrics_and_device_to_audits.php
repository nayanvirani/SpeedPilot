<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_pages', function (Blueprint $table) {
            $table->string('device')->default('mobile')->after('url'); // mobile | desktop
            $table->unsignedInteger('tbt')->nullable()->after('ttfb'); // total blocking time, ms
            $table->decimal('speed_index', 8, 3)->nullable()->after('tbt');
            $table->unsignedBigInteger('image_weight_bytes')->nullable()->after('css_weight_bytes');
            $table->unsignedInteger('request_count')->nullable()->after('image_weight_bytes');
        });

        Schema::table('audits', function (Blueprint $table) {
            // Aggregated the same way as the other metrics (averaged across
            // scanned pages/devices in RunAuditJob) - matches spec's scan
            // fields (TBT, Speed Index, request count, image size).
            $table->unsignedInteger('tbt')->nullable()->after('ttfb');
            $table->decimal('speed_index', 8, 3)->nullable()->after('tbt');
            $table->unsignedBigInteger('image_weight_bytes')->nullable()->after('css_weight_bytes');
            $table->unsignedInteger('request_count')->nullable()->after('image_weight_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('audit_pages', function (Blueprint $table) {
            $table->dropColumn(['device', 'tbt', 'speed_index', 'image_weight_bytes', 'request_count']);
        });

        Schema::table('audits', function (Blueprint $table) {
            $table->dropColumn(['tbt', 'speed_index', 'image_weight_bytes', 'request_count']);
        });
    }
};
