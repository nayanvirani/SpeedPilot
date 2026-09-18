<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->string('category'); // image | js | css | theme | third_party
            $table->string('severity'); // high | medium | low
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('fix_available')->default(false);
            $table->string('risk_tier'); // safe | medium | high
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_issues');
    }
};
