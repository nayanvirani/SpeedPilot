<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Full subscription history, not just "what plan is this shop on now"
 * (shop_installations.plan stays as a fast-path cache of the current one).
 * One row per distinct Shopify charge id - upgrades/downgrades/cancels each
 * get their own row rather than overwriting the last, so admins can see a
 * shop's plan history, not just its current snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_installation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('shopify_charge_id')->unique();
            $table->string('shopify_plan_name')->nullable();
            $table->string('status');
            $table->timestamp('current_period_end')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
