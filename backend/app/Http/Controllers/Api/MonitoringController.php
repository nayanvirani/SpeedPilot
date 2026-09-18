<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopInstallation;
use App\Services\PlanPolicy;
use Illuminate\Http\Request;

class MonitoringController extends Controller
{
    public function trend(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');
        $policy = new PlanPolicy($shop);

        $runs = $shop->monitoringRuns()
            ->with('audit:id,score,created_at')
            ->where('created_at', '>=', now()->subDays($policy->historyDays() ?: 7))
            ->orderBy('run_at')
            ->get();

        return response()->json(['monitoring_runs' => $runs]);
    }
}
