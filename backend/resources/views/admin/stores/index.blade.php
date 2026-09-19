@extends('layouts.admin')

@section('title', 'Stores')

@section('content')
    <h1 class="text-2xl font-semibold mb-6">Stores</h1>

    <form method="GET" class="flex flex-wrap gap-3 mb-6">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Search domain..."
               class="rounded-md border-slate-300 shadow-sm text-sm">
        <select name="plan" class="rounded-md border-slate-300 shadow-sm text-sm">
            <option value="">All plans</option>
            @foreach ($plans as $plan)
                <option value="{{ $plan }}" @selected(request('plan') === $plan)>{{ ucfirst($plan) }}</option>
            @endforeach
        </select>
        <select name="status" class="rounded-md border-slate-300 shadow-sm text-sm">
            <option value="">All statuses</option>
            <option value="active" @selected(request('status') === 'active')>Active</option>
            <option value="uninstalled" @selected(request('status') === 'uninstalled')>Uninstalled</option>
        </select>
        <button type="submit" class="bg-slate-900 text-white rounded-md px-4 py-1.5 text-sm">Filter</button>
    </form>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-slate-500">
                <tr>
                    <th class="px-4 py-2">Domain</th>
                    <th class="px-4 py-2">Plan</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Audits</th>
                    <th class="px-4 py-2">Installed</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($stores as $store)
                    <tr class="border-t">
                        <td class="px-4 py-2">
                            <a href="{{ route('admin.stores.show', $store) }}" class="text-slate-800 hover:underline">{{ $store->shop_domain }}</a>
                        </td>
                        <td class="px-4 py-2 capitalize">{{ $store->plan }}</td>
                        <td class="px-4 py-2">
                            @if ($store->uninstalled_at)
                                <span class="text-red-600">Uninstalled</span>
                            @else
                                <span class="text-green-600">Active</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">{{ $store->audits_count }}</td>
                        <td class="px-4 py-2 text-slate-500">{{ $store->installed_at?->format('Y-m-d') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-slate-400">No stores match.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $stores->links() }}</div>
@endsection
