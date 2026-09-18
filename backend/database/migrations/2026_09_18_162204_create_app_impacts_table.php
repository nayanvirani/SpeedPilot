<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_impacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->string('app_name');
            $table->string('script_url')->nullable();
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->decimal('estimated_blocking_ms', 8, 2)->nullable();
            $table->string('impact_level'); // high | medium | low
            $table->string('status')->default('active'); // active | disabled | delayed | excluded
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_impacts');
    }
};
