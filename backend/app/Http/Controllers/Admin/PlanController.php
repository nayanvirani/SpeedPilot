<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;

/**
 * Lets the admin edit feature gating (auto-fixes, limits, monitoring) without
 * a redeploy. Billing is Shopify Managed Pricing - shopify_plan_name is what
 * maps a row here to the matching plan Shopify's Partner Dashboard reports
 * as active; price is display-only, not what's actually charged.
 */
class PlanController extends Controller
{
    public function index()
    {
        $plans = Plan::orderBy('sort_order')->get();

        return view('admin.plans.index', compact('plans'));
    }

    public function edit(Plan $plan)
    {
        return view('admin.plans.edit', compact('plan'));
    }

    public function update(Request $request, Plan $plan)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'shopify_plan_name' => 'nullable|string|max:255',
            'price' => 'required|numeric|min:0',
            'trial_days' => 'required|integer|min:0',
            'script_rule_limit' => 'nullable|integer|min:0',
            'auto_fix_limit' => 'nullable|integer|min:0',
            'history_days' => 'required|integer|min:0',
            'monitoring' => 'nullable|string|in:basic,advanced,advanced_priority',
            'auto_fixes' => 'nullable|boolean',
            'medium_risk_fixes' => 'nullable|boolean',
            'high_risk_recommendations' => 'nullable|boolean',
            'ai_recommendations' => 'nullable|boolean',
            'active' => 'nullable|boolean',
            'sort_order' => 'required|integer|min:0',
        ]);

        $data['auto_fixes'] = $request->boolean('auto_fixes');
        $data['medium_risk_fixes'] = $request->boolean('medium_risk_fixes');
        $data['high_risk_recommendations'] = $request->boolean('high_risk_recommendations');
        $data['ai_recommendations'] = $request->boolean('ai_recommendations');
        $data['active'] = $request->boolean('active');

        $plan->update($data);

        return redirect()->route('admin.plans.index')->with('status', "{$plan->name} plan updated.");
    }
}
