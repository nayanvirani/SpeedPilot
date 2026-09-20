@extends('layouts.admin')

@section('title', 'Plans')

@section('content')
    <div class="flex items-center justify-between mb-2">
        <h1 class="text-2xl font-semibold">Plans</h1>
        <a href="{{ route('admin.plans.create') }}" class="text-sm bg-slate-900 text-white rounded-md px-3 py-1.5">
            New plan
        </a>
    </div>
    <p class="text-sm text-slate-500 mb-6">
        Billing itself is Shopify Managed Pricing now - merchants pick a plan and pay through
        Shopify's own screen, configured in the
        <a href="https://partners.shopify.com" target="_blank" rel="noopener" class="underline">Partner Dashboard</a>'s
        Pricing page, not here. What you edit on this page controls <em>feature gating only</em>
        (auto-fixes, script rule limits, monitoring, etc.) - <code class="bg-slate-100 px-1 rounded">shopify_plan_name</code>
        below is what maps a plan here to the matching plan name in Shopify's pricing config, and
        <code class="bg-slate-100 px-1 rounded">price</code> is display-only (shown in-app, not charged).
    </p>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-slate-500">
                <tr>
                    <th class="px-4 py-2">Key</th>
                    <th class="px-4 py-2">Name</th>
                    <th class="px-4 py-2">Shopify plan name</th>
                    <th class="px-4 py-2">Price</th>
                    <th class="px-4 py-2">Auto-fixes</th>
                    <th class="px-4 py-2">Pages/scan</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($plans as $plan)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-mono text-xs text-slate-500">{{ $plan->key }}</td>
                        <td class="px-4 py-2 font-medium">{{ $plan->name }}</td>
                        <td class="px-4 py-2 font-mono text-xs">{{ $plan->shopify_plan_name ?? '—' }}</td>
                        <td class="px-4 py-2">${{ number_format($plan->price, 2) }}/mo</td>
                        <td class="px-4 py-2">{{ $plan->auto_fixes ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-2">{{ $plan->pages_per_scan }}</td>
                        <td class="px-4 py-2">
                            @if ($plan->active)
                                <span class="text-green-600">Active</span>
                            @else
                                <span class="text-slate-400">Hidden</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right space-x-3">
                            <a href="{{ route('admin.plans.edit', $plan) }}" class="text-slate-700 hover:underline">Edit</a>
                            <form method="POST" action="{{ route('admin.plans.destroy', $plan) }}" class="inline"
                                  onsubmit="return confirm('Delete the {{ $plan->name }} plan? Shops currently on it will show as unsubscribed.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600 hover:underline">Delete</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
