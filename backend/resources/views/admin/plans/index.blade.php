@extends('layouts.admin')

@section('title', 'Plans')

@section('content')
    <h1 class="text-2xl font-semibold mb-2">Plans</h1>
    <p class="text-sm text-slate-500 mb-6">
        Editing a plan here changes what it actually costs and unlocks the next time a
        merchant subscribes via Shopify Billing - it's live, not a draft.
    </p>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-slate-500">
                <tr>
                    <th class="px-4 py-2">Key</th>
                    <th class="px-4 py-2">Name</th>
                    <th class="px-4 py-2">Price</th>
                    <th class="px-4 py-2">Auto-fixes</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($plans as $plan)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-mono text-xs text-slate-500">{{ $plan->key }}</td>
                        <td class="px-4 py-2 font-medium">{{ $plan->name }}</td>
                        <td class="px-4 py-2">${{ number_format($plan->price, 2) }}/mo</td>
                        <td class="px-4 py-2">{{ $plan->auto_fixes ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-2">
                            @if ($plan->active)
                                <span class="text-green-600">Active</span>
                            @else
                                <span class="text-slate-400">Hidden</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            <a href="{{ route('admin.plans.edit', $plan) }}" class="text-slate-700 hover:underline">Edit</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
