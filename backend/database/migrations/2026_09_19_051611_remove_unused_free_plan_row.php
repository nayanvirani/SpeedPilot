<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * There is no "Free" plan in Shopify Managed Pricing - only Starter and
 * Pro exist (confirmed by the app owner). The 'free' row was a speculative
 * mapping target that will never actually match an incoming subscription
 * name, so it's just dead data.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('plans')->where('key', 'free')->delete();
    }

    public function down(): void
    {
        DB::table('plans')->insert([
            'key' => 'free',
            'name' => 'Free',
            'shopify_plan_name' => 'Free',
            'price' => 0,
            'trial_days' => 0,
            'script_rule_limit' => 0,
            'auto_fix_limit' => 0,
            'history_days' => 0,
            'auto_fixes' => false,
            'medium_risk_fixes' => false,
            'high_risk_recommendations' => false,
            'ai_recommendations' => false,
            'monitoring' => null,
            'active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
