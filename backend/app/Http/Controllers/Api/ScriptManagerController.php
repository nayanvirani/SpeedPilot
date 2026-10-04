<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScriptPageRule;
use App\Models\ShopInstallation;
use App\Services\PlanPolicy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Smart Script Manager: per-app, per-page-type load control, additive to
 * the simple global Disable/Delay buttons already on the App & Script
 * Impact page - see ScriptPageRule's migration docblock and
 * InterceptorController::serve() for how a rule here actually changes what
 * loads on the storefront.
 */
class ScriptManagerController extends Controller
{
    private const PAGE_TYPES = ['home', 'product', 'collection', 'cart', 'search', 'blog', 'custom'];

    public function index(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $apps = $shop->latestAudit()?->appImpacts()->where('is_platform', false)->get(['app_name'])
            ->pluck('app_name')->unique()->values() ?? collect();

        return response()->json([
            'apps' => $apps,
            'page_types' => self::PAGE_TYPES,
            'triggers' => ScriptPageRule::TRIGGERS,
            'default_page_type' => ScriptPageRule::DEFAULT_PAGE_TYPE,
            'rules' => $shop->scriptPageRules()->orderBy('app_name')->orderBy('page_type')->get(),
        ]);
    }

    public function upsert(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        if (! (new PlanPolicy($shop))->hasPaidPlan()) {
            return response()->json(['error' => 'Smart Script Manager is not available on the Free plan.'], 403);
        }

        $data = $request->validate([
            'app_name' => 'required|string',
            'page_type' => ['required', Rule::in([...self::PAGE_TYPES, ScriptPageRule::DEFAULT_PAGE_TYPE])],
            'trigger' => ['required', Rule::in(ScriptPageRule::TRIGGERS)],
            'delay_seconds' => 'nullable|integer|min:1|max:60',
        ]);

        $rule = $shop->scriptPageRules()->updateOrCreate(
            ['app_name' => $data['app_name'], 'page_type' => $data['page_type']],
            ['trigger' => $data['trigger'], 'delay_seconds' => $data['delay_seconds'] ?? null],
        );

        return response()->json(['rule' => $rule]);
    }

    public function destroy(Request $request, int $id)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $shop->scriptPageRules()->where('id', $id)->delete();

        return response()->json([], 204);
    }
}
