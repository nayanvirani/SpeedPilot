<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reintroduces a real Free tier: unlike the two earlier "free" rows this
 * table has had (both deleted - see their migrations' docblocks, "there is
 * no free plan in Shopify Managed Pricing"), this one is actually used by
 * the app itself regardless of what Shopify's Partner Dashboard offers -
 * App.jsx no longer hard-blocks a shop with no paid subscription, so every
 * such shop now resolves to this row's limits (scan + display only, no
 * fixes, no recommendations, no monitoring) instead of being paywalled
 * out of the app entirely. If a "Free" option is ever added to the
 * Shopify-side pricing page too, shopify_plan_name below is what maps that
 * webhook report back to this same row.
 */
return new class extends Migration
{
    public function up(): void
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
            'pages_per_scan' => 1,
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

    public function down(): void
    {
        DB::table('plans')->where('key', 'free')->delete();
    }
};
