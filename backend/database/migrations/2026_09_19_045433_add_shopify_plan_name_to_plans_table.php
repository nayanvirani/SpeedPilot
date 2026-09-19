<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shopify Managed Pricing (configured in the Partner Dashboard, not via
 * appSubscriptionCreate) reports the active plan back to us only by its
 * display name - "Starter", "Pro", etc. This column is the mapping from
 * that name to our internal plan key, since the two aren't guaranteed to
 * match and shouldn't be assumed to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('shopify_plan_name')->nullable()->after('name');
        });

        DB::table('plans')->where('key', 'starter')->update(['shopify_plan_name' => 'Starter']);
        DB::table('plans')->where('key', 'pro')->update(['shopify_plan_name' => 'Pro']);

        // A row for Shopify's "Free" managed-pricing option so it resolves to
        // a known plan (all features false/0, same effect as no plan at all,
        // but recognized rather than silently unmatched).
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

    public function down(): void
    {
        DB::table('plans')->where('key', 'free')->delete();

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('shopify_plan_name');
        });
    }
};
