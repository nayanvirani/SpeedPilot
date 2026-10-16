<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\ShopInstallation;
use App\Services\Shopify\BillingService;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    public function index(Request $request)
    {
        $query = ShopInstallation::query()->withCount('audits');

        if ($search = $request->string('search')->toString()) {
            $query->where('shop_domain', 'like', "%{$search}%");
        }

        if ($plan = $request->string('plan')->toString()) {
            $query->where('plan', $plan);
        }

        if ($request->string('status')->toString() === 'active') {
            $query->whereNull('uninstalled_at');
        } elseif ($request->string('status')->toString() === 'uninstalled') {
            $query->whereNotNull('uninstalled_at');
        }

        $stores = $query->latest('installed_at')->paginate(20)->withQueryString();
        $plans = Plan::orderBy('sort_order')->pluck('key');

        return view('admin.stores.index', compact('stores', 'plans'));
    }

    public function show(ShopInstallation $store)
    {
        $store->load(['audits' => fn ($q) => $q->latest()->limit(10)]);

        return view('admin.stores.show', compact('store'));
    }

    /**
     * Manual safety net for a missed/failed app_subscriptions/update webhook -
     * re-queries Shopify directly for the shop's active subscription rather
     * than waiting for another webhook delivery.
     */
    public function resyncPlan(ShopInstallation $store, BillingService $billing)
    {
        if (! $store->access_token) {
            return back()->with('status', 'Cannot resync: no access token on file for this store.');
        }

        $billing->syncActivePlanViaApi($store);

        return back()->with('status', "Plan resynced: {$store->fresh()->plan}");
    }

    /**
     * Unblocks this store's manual-scan cooldown immediately (support
     * requests, active debugging sessions) without touching audit history -
     * see AuditController::nextManualScanAt() for the matching check.
     */
    public function resetScanCooldown(ShopInstallation $store)
    {
        $store->update(['scan_cooldown_reset_at' => now()]);

        return back()->with('status', 'Scan cooldown reset - the next manual scan will run immediately.');
    }
}
