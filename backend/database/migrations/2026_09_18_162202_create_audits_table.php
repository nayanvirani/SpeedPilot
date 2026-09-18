<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_installation_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->nullable();
            $table->decimal('lcp', 8, 3)->nullable();
            $table->decimal('inp', 8, 3)->nullable();
            $table->decimal('cls', 8, 4)->nullable();
            $table->decimal('fcp', 8, 3)->nullable();
            $table->decimal('ttfb', 8, 3)->nullable();
            $table->unsignedBigInteger('page_weight_bytes')->nullable();
            $table->unsignedBigInteger('js_weight_bytes')->nullable();
            $table->unsignedBigInteger('css_weight_bytes')->nullable();
            $table->string('source')->default('lab'); // lab | field
            $table->json('raw_report')->nullable();
            $table->string('status')->default('pending'); // pending | running | complete | failed
            $table->string('url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audits');
    }
};
