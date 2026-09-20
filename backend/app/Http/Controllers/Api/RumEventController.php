<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopInstallation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public ingestion endpoint for the Theme App Extension's web-vitals snippet
 * (real visitor traffic, no App Bridge session token available). Rate-limited
 * via the "rum" throttle group in routes/api.php to absorb storefront traffic
 * without needing per-visitor auth.
 */
class RumEventController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'shop_domain' => 'required|string',
            'page_url' => 'required|string|max:2048',
            'lcp' => 'nullable|numeric',
            'inp' => 'nullable|numeric',
            'cls' => 'nullable|numeric',
        ]);

        $shop = ShopInstallation::where('shop_domain', $data['shop_domain'])
            ->whereNull('uninstalled_at')
            ->first();

        if (! $shop) {
            return response()->json(['error' => 'Unknown shop'], 404);
        }

        $shop->rumEvents()->create([
            'page_url' => $data['page_url'],
            'lcp' => $data['lcp'] ?? null,
            'inp' => $data['inp'] ?? null,
            'cls' => $data['cls'] ?? null,
            'recorded_at' => now(),
        ]);

        return response()->json([], 201);
    }

    /**
     * Real-visitor field data, alongside the lab scores everywhere else in
     * the app - Google's own CWV thresholds are defined against the 75th
     * percentile of real traffic, not an average, so that's what merchants
     * need here rather than a plain mean.
     */
    public function summary(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $since = now()->subDays(28);

        $percentiles = DB::table('rum_events')
            ->where('shop_installation_id', $shop->id)
            ->where('recorded_at', '>=', $since)
            ->selectRaw('
                percentile_cont(0.75) within group (order by lcp) as p75_lcp,
                percentile_cont(0.75) within group (order by inp) as p75_inp,
                percentile_cont(0.75) within group (order by cls) as p75_cls,
                count(*) as sample_count
            ')
            ->first();

        return response()->json([
            'window_days' => 28,
            'sample_count' => (int) ($percentiles->sample_count ?? 0),
            'p75_lcp' => $percentiles->p75_lcp !== null ? (float) $percentiles->p75_lcp : null,
            'p75_inp' => $percentiles->p75_inp !== null ? (float) $percentiles->p75_inp : null,
            'p75_cls' => $percentiles->p75_cls !== null ? (float) $percentiles->p75_cls : null,
        ]);
    }
}
