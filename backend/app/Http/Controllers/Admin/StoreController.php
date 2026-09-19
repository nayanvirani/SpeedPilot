<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\ShopInstallation;
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
}
