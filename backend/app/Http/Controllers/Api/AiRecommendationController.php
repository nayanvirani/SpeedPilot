<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\ShopInstallation;
use App\Services\Ai\AiRecommendationService;
use App\Services\PlanPolicy;
use Illuminate\Http\Request;

class AiRecommendationController extends Controller
{
    public function show(Request $request, int $issueId, AiRecommendationService $ai)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        if (! (new PlanPolicy($shop))->hasAiRecommendations()) {
            return response()->json(['error' => 'AI recommendations are not available on this plan'], 403);
        }

        $issue = AuditIssue::whereHas(
            'audit',
            fn ($q) => $q->where('shop_installation_id', $shop->id)
        )->findOrFail($issueId);

        return response()->json(['recommendation' => $ai->recommend($issue)]);
    }

    public function prioritize(Request $request, int $auditId, AiRecommendationService $ai)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        if (! (new PlanPolicy($shop))->hasAiRecommendations()) {
            return response()->json(['error' => 'AI recommendations are not available on this plan'], 403);
        }

        $audit = Audit::where('shop_installation_id', $shop->id)->findOrFail($auditId);

        return response()->json(['plan' => $ai->prioritize($audit)]);
    }
}
