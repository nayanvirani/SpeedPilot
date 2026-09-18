<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('optimization_id')->constrained()->cascadeOnDelete();
            $table->string('theme_id');
            $table->string('asset_key');
            $table->longText('original_content');
            $table->string('checksum');
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_backups');
    }
};
