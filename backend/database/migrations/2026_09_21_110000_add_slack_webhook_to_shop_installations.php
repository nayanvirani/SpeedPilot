<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->text('slack_webhook_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->dropColumn('slack_webhook_url');
        });
    }
};
