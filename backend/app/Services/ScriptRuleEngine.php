<?php

namespace App\Services;

use App\Models\AppImpact;
use App\Models\ShopInstallation;

/**
 * Evaluates a shop's script_rules against detected third-party scripts to
 * decide delay/disable/exclude automatically, within the plan's rule-count
 * limit (PlanPolicy::scriptRuleLimit).
 */
class ScriptRuleEngine
{
    public function apply(ShopInstallation $shop, AppImpact $impact): ?string
    {
        $rule = $shop->scriptRules()
            ->where('active', true)
            ->get()
            ->first(fn ($rule) => $impact->script_url && $rule->matches($impact->script_url));

        if (! $rule) {
            return null;
        }

        $impact->update(['status' => $rule->action]);

        return $rule->action;
    }

    public function withinLimit(ShopInstallation $shop): bool
    {
        $limit = (new PlanPolicy($shop))->scriptRuleLimit();

        if ($limit === null) {
            return true; // unlimited
        }

        return $shop->scriptRules()->where('active', true)->count() < $limit;
    }
}
