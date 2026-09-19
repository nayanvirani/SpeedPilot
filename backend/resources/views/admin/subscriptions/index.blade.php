@extends('layouts.admin')

@section('title', 'Subscriptions')

@section('content')
    <h1 class="text-2xl font-semibold mb-6">Subscriptions</h1>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-slate-500">
                <tr>
                    <th class="px-4 py-2">Shop</th>
                    <th class="px-4 py-2">Plan</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Current period ends</th>
                    <th class="px-4 py-2">Created</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($subscriptions as $subscription)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-medium">{{ $subscription->shop->shop_domain }}</td>
                        <td class="px-4 py-2">{{ $subscription->plan?->name ?? $subscription->shopify_plan_name ?? '—' }}</td>
                        <td class="px-4 py-2">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $subscription->status === 'active' ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500' }}">
                                {{ ucfirst($subscription->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-slate-500">{{ $subscription->current_period_end?->format('M j, Y') ?? '—' }}</td>
                        <td class="px-4 py-2 text-slate-500">{{ $subscription->created_at->format('M j, Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-slate-400">No subscriptions yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $subscriptions->links() }}</div>
@endsection
