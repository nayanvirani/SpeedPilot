<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Audit;
use App\Models\Optimization;
use App\Models\RumEvent;
use App\Models\ShopInstallation;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $totalShops = ShopInstallation::count();
        $activeShops = ShopInstallation::whereNull('uninstalled_at')->count();
        $uninstalledShops = $totalShops - $activeShops;

        $shopsByPlan = ShopInstallation::whereNull('uninstalled_at')
            ->select('plan', DB::raw('count(*) as total'))
            ->groupBy('plan')
            ->pluck('total', 'plan');

        $mrr = collect(config('speedpilot.plans'))
            ->reduce(function (float $carry, array $plan, string $key) use ($shopsByPlan) {
                return $carry + ($plan['price'] * ($shopsByPlan[$key] ?? 0));
            }, 0.0);

        $stats = [
            'total_shops' => $totalShops,
            'active_shops' => $activeShops,
            'uninstalled_shops' => $uninstalledShops,
            'shops_by_plan' => $shopsByPlan,
            'mrr' => $mrr,
            'total_audits' => Audit::count(),
            'audits_last_7_days' => Audit::where('created_at', '>=', now()->subDays(7))->count(),
            'total_optimizations_applied' => Optimization::where('status', 'applied')->count(),
            'total_rollbacks' => Optimization::where('status', 'rolled_back')->count(),
            'total_rum_events' => RumEvent::count(),
        ];

        $recentShops = ShopInstallation::latest('installed_at')->limit(5)->get();

        return view('admin.dashboard', compact('stats', 'recentShops'));
    }
}
