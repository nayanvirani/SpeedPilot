<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('optimized_themes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_installation_id')->constrained()->cascadeOnDelete();
            $table->string('duplicate_theme_id');
            $table->string('source_theme_id');
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('diverged')->default(false);
            $table->json('divergence_meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optimized_themes');
    }
};
