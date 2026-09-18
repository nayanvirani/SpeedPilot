<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopInstallation;
use Illuminate\Http\Request;

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
}
