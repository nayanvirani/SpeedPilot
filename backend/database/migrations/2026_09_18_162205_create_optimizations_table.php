<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('optimizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_installation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_issue_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // e.g. image_dimensions, lazy_load, defer_script, css_defer
            $table->string('risk_tier'); // safe | medium | high
            $table->string('status')->default('recommended'); // recommended | applied | rolled_back
            $table->timestamp('applied_at')->nullable();
            $table->string('theme_id')->nullable();
            $table->string('asset_key')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optimizations');
    }
};
