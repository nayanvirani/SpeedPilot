@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
    <h1 class="text-2xl font-semibold mb-6">Dashboard</h1>

    @if ($stats['theme_write_blocked_shops'] > 0)
        <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-lg p-4 mb-6 text-sm">
            <strong>{{ $stats['theme_write_blocked_shops'] }} store{{ $stats['theme_write_blocked_shops'] === 1 ? '' : 's' }}</strong>
            hit Shopify's <code>ACCESS_DENIED</code> on a theme write - the app has <code>write_themes</code> but
            needs Shopify's protected-scope exemption approved before any auto-fix or App &amp; Script Impact
            action can actually write to a theme. Submit the exemption request:
            <a href="{{ $exemptionFormUrl }}" target="_blank" rel="noreferrer" class="underline">exemption request form</a>.
            This is app-wide, not per-store - merchants never see this.
        </div>
    @endif

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-lg shadow p-5">
            <div class="text-sm text-slate-500">Active stores</div>
            <div class="text-3xl font-semibold">{{ $stats['active_shops'] }}</div>
            <div class="text-xs text-slate-400">{{ $stats['uninstalled_shops'] }} uninstalled</div>
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <div class="text-sm text-slate-500">MRR (estimate)</div>
            <div class="text-3xl font-semibold">${{ number_format($stats['mrr'], 0) }}</div>
            <div class="text-xs text-slate-400">from Shopify Billing plans</div>
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <div class="text-sm text-slate-500">Audits (7 days)</div>
            <div class="text-3xl font-semibold">{{ $stats['audits_last_7_days'] }}</div>
            <div class="text-xs text-slate-400">{{ $stats['total_audits'] }} all-time</div>
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <div class="text-sm text-slate-500">Fixes applied</div>
            <div class="text-3xl font-semibold">{{ $stats['total_optimizations_applied'] }}</div>
            <div class="text-xs text-slate-400">{{ $stats['total_rollbacks'] }} rolled back</div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-white rounded-lg shadow p-5">
            <h2 class="font-medium mb-3">Stores by plan</h2>
            <ul class="space-y-1 text-sm">
                @forelse ($stats['shops_by_plan'] as $plan => $count)
                    <li class="flex justify-between">
                        <span class="capitalize">{{ $plan }}</span>
                        <span class="font-medium">{{ $count }}</span>
                    </li>
                @empty
                    <li class="text-slate-400">No active stores yet.</li>
                @endforelse
            </ul>
        </div>

        <div class="bg-white rounded-lg shadow p-5">
            <h2 class="font-medium mb-3">Recently installed</h2>
            <ul class="space-y-1 text-sm">
                @forelse ($recentShops as $shop)
                    <li class="flex justify-between">
                        <a href="{{ route('admin.stores.show', $shop) }}" class="text-slate-700 hover:underline">{{ $shop->shop_domain }}</a>
                        <span class="text-slate-400">{{ $shop->installed_at?->diffForHumans() }}</span>
                    </li>
                @empty
                    <li class="text-slate-400">No stores yet.</li>
                @endforelse
            </ul>
        </div>
    </div>
@endsection
