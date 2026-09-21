<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Audit;
use App\Models\Optimization;
use App\Models\Plan;
use App\Models\RumEvent;
use App\Models\ShopInstallation;
use App\Services\Shopify\ThemeWriteAccessDeniedException;
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

        $mrr = Plan::all()
            ->reduce(function (float $carry, Plan $plan) use ($shopsByPlan) {
                return $carry + ((float) $plan->price * ($shopsByPlan[$plan->key] ?? 0));
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
            // Shopify's write_themes protected-scope exemption is an
            // app-wide, developer-side approval - not something any
            // individual merchant can act on, so this is surfaced only
            // here, never on a merchant's own Dashboard.
            'theme_write_blocked_shops' => ShopInstallation::whereNull('uninstalled_at')
                ->whereNotNull('theme_write_blocked_at')
                ->count(),
        ];

        $recentShops = ShopInstallation::latest('installed_at')->limit(5)->get();
        $exemptionFormUrl = ThemeWriteAccessDeniedException::EXEMPTION_FORM_URL;

        return view('admin.dashboard', compact('stats', 'recentShops', 'exemptionFormUrl'));
    }
}
