<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Going from two tiers to one - Starter survives (same price, $29.99) and
 * absorbs every feature Pro had; Pro is retired. plan_id on subscriptions
 * is nullOnDelete, so deleting the Pro row is safe - it just clears that
 * historical link, the shopify_plan_name string on those rows still
 * records what they were on at the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('plans')->where('key', 'starter')->update([
            'script_rule_limit' => null,
            'auto_fix_limit' => null,
            'history_days' => 90,
            'pages_per_scan' => 3,
            'auto_fixes' => true,
            'medium_risk_fixes' => true,
            'high_risk_recommendations' => true,
            'ai_recommendations' => true,
            'monitoring' => 'advanced_priority',
        ]);

        // Any shop currently reading as "pro" via the fast-path cache column
        // should land on the surviving plan, not silently become
        // unsubscribed once the pro row is gone.
        DB::table('shop_installations')->where('plan', 'pro')->update(['plan' => 'starter']);

        DB::table('plans')->where('key', 'pro')->delete();
    }

    public function down(): void
    {
        // Feature/price values are intentionally not reverted - this is a
        // one-way pricing decision, not a schema change to roll back.
    }
};
