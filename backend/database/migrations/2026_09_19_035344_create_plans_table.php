<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing plans move from a static config file into the database so the
 * admin panel can edit price/limits/features without a code deploy.
 * BillingService reads the price from here when creating the actual
 * Shopify RecurringApplicationCharge, so an edit here changes what
 * merchants are billed - it's the real source of truth, not a display copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->decimal('price', 8, 2);
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->unsignedInteger('script_rule_limit')->nullable(); // null = unlimited
            $table->unsignedInteger('auto_fix_limit')->nullable(); // null = unlimited
            $table->unsignedSmallInteger('history_days')->default(0);
            $table->boolean('auto_fixes')->default(false);
            $table->boolean('medium_risk_fixes')->default(false);
            $table->boolean('high_risk_recommendations')->default(false);
            $table->boolean('ai_recommendations')->default(false);
            $table->string('monitoring')->nullable(); // null|basic|advanced|advanced_priority
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::table('plans')->insert([
            [
                'key' => 'starter',
                'name' => 'Starter',
                'price' => 29.99,
                'trial_days' => 7,
                'script_rule_limit' => 3,
                'auto_fix_limit' => null,
                'history_days' => 30,
                'auto_fixes' => true,
                'medium_risk_fixes' => false,
                'high_risk_recommendations' => false,
                'ai_recommendations' => false,
                'monitoring' => 'basic',
                'active' => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'pro',
                'name' => 'Pro',
                'price' => 49.99,
                'trial_days' => 7,
                'script_rule_limit' => null,
                'auto_fix_limit' => null,
                'history_days' => 90,
                'auto_fixes' => true,
                'medium_risk_fixes' => true,
                'high_risk_recommendations' => true,
                'ai_recommendations' => true,
                'monitoring' => 'advanced_priority',
                'active' => true,
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
