<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rum_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_installation_id')->constrained()->cascadeOnDelete();
            $table->string('page_url');
            $table->decimal('lcp', 8, 3)->nullable();
            $table->decimal('inp', 8, 3)->nullable();
            $table->decimal('cls', 8, 4)->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['shop_installation_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rum_events');
    }
};
