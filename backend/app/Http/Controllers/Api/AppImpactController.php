<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppImpact;
use App\Models\ShopInstallation;
use App\Services\ScriptRuleEngine;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The app/script impact table - the spec's core differentiator. Lets the
 * merchant act per-row: Optimize / Disable / Delay / Exclude, always with
 * rollback available (rollback here just means switching status back to
 * "active", since no destructive theme write happens for this action type).
 */
class AppImpactController extends Controller
{
    public function index(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $latestAudit = $shop->latestAudit();

        return response()->json([
            'app_impacts' => $latestAudit?->appImpacts ?? [],
        ]);
    }

    public function updateStatus(Request $request, int $id, ScriptRuleEngine $rules)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'disabled', 'delayed', 'excluded'])],
            'persist_as_rule' => 'sometimes|boolean',
        ]);

        $impact = AppImpact::whereHas(
            'audit',
            fn ($q) => $q->where('shop_installation_id', $shop->id)
        )->findOrFail($id);

        $impact->update(['status' => $data['status']]);

        if (($data['persist_as_rule'] ?? false) && $data['status'] !== 'active' && $impact->script_url && $rules->withinLimit($shop)) {
            $shop->scriptRules()->create([
                'script_pattern' => $impact->script_url,
                'action' => $data['status'],
            ]);
        }

        return response()->json(['app_impact' => $impact]);
    }
}
