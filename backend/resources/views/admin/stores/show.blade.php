@extends('layouts.admin')

@section('title', $store->shop_domain)

@section('content')
    <a href="{{ route('admin.stores.index') }}" class="text-sm text-slate-500 hover:underline">&larr; Back to stores</a>
    <h1 class="text-2xl font-semibold mt-2 mb-6">{{ $store->shop_domain }}</h1>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8 text-sm">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-slate-500">Plan</div>
            <div class="font-medium capitalize">{{ $store->plan }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-slate-500">Status</div>
            <div class="font-medium">{{ $store->uninstalled_at ? 'Uninstalled' : 'Active' }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-slate-500">Installed</div>
            <div class="font-medium">{{ $store->installed_at?->format('Y-m-d') }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-slate-500">Subscription ID</div>
            <div class="font-medium text-xs break-all">{{ $store->shopify_subscription_id ?? '—' }}</div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-4 py-3 border-b font-medium">Recent audits</div>
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-slate-500">
                <tr>
                    <th class="px-4 py-2">Date</th>
                    <th class="px-4 py-2">Score</th>
                    <th class="px-4 py-2">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($store->audits as $audit)
                    <tr class="border-t">
                        <td class="px-4 py-2">{{ $audit->created_at->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-2">{{ $audit->score ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $audit->status }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-6 text-center text-slate-400">No audits yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
