<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            // Optional merchant-entered inputs so the revenue-impact estimate
            // on the Dashboard can turn a load-time delta into a personalized
            // dollar range instead of just a percentage - see
            // MonitoringController::beforeAfter().
            $table->decimal('avg_order_value', 10, 2)->nullable();
            $table->unsignedInteger('monthly_orders')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_installations', function (Blueprint $table) {
            $table->dropColumn(['avg_order_value', 'monthly_orders']);
        });
    }
};
