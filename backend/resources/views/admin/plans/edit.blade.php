@extends('layouts.admin')

@section('title', "Edit {$plan->name}")

@section('content')
    <a href="{{ route('admin.plans.index') }}" class="text-sm text-slate-500 hover:underline">&larr; Back to plans</a>
    <h1 class="text-2xl font-semibold mt-2 mb-6">Edit {{ $plan->name }} <span class="text-sm font-normal text-slate-400 font-mono">({{ $plan->key }})</span></h1>

    @if ($errors->any())
        <div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm max-w-lg">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.plans.update', $plan) }}" class="bg-white rounded-lg shadow p-6 space-y-5 max-w-lg">
        @csrf
        @method('PUT')

        <div>
            <label class="block text-sm font-medium text-slate-700">Display name</label>
            <input type="text" name="name" value="{{ old('name', $plan->name) }}" required
                   class="mt-1 w-full rounded-md border-slate-300 shadow-sm text-sm">
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700">
                Shopify plan name <span class="text-slate-400 font-normal">(must exactly match the plan name in Shopify's Managed Pricing config)</span>
            </label>
            <input type="text" name="shopify_plan_name" value="{{ old('shopify_plan_name', $plan->shopify_plan_name) }}"
                   class="mt-1 w-full rounded-md border-slate-300 shadow-sm text-sm font-mono">
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700">Price (display only - USD/mo)</label>
                <input type="number" step="0.01" min="0" name="price" value="{{ old('price', $plan->price) }}" required
                       class="mt-1 w-full rounded-md border-slate-300 shadow-sm text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Trial days</label>
                <input type="number" min="0" name="trial_days" value="{{ old('trial_days', $plan->trial_days) }}" required
                       class="mt-1 w-full rounded-md border-slate-300 shadow-sm text-sm">
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700">Script rule limit</label>
                <input type="number" min="0" name="script_rule_limit" value="{{ old('script_rule_limit', $plan->script_rule_limit) }}"
                       placeholder="blank = unlimited"
                       class="mt-1 w-full rounded-md border-slate-300 shadow-sm text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Auto-fix limit</label>
                <input type="number" min="0" name="auto_fix_limit" value="{{ old('auto_fix_limit', $plan->auto_fix_limit) }}"
                       placeholder="blank = unlimited"
                       class="mt-1 w-full rounded-md border-slate-300 shadow-sm text-sm">
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700">History days</label>
                <input type="number" min="0" name="history_days" value="{{ old('history_days', $plan->history_days) }}" required
                       class="mt-1 w-full rounded-md border-slate-300 shadow-sm text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Monitoring level</label>
                <select name="monitoring" class="mt-1 w-full rounded-md border-slate-300 shadow-sm text-sm">
                    <option value="" @selected(!$plan->monitoring)>None</option>
                    <option value="basic" @selected($plan->monitoring === 'basic')>Basic</option>
                    <option value="advanced" @selected($plan->monitoring === 'advanced')>Advanced</option>
                    <option value="advanced_priority" @selected($plan->monitoring === 'advanced_priority')>Advanced + priority</option>
                </select>
            </div>
        </div>

        <div class="space-y-2 pt-2 border-t">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="auto_fixes" value="1" class="rounded border-slate-300" @checked($plan->auto_fixes)>
                Automatic safe fixes
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="medium_risk_fixes" value="1" class="rounded border-slate-300" @checked($plan->medium_risk_fixes)>
                Medium-risk fixes via preview theme
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="high_risk_recommendations" value="1" class="rounded border-slate-300" @checked($plan->high_risk_recommendations)>
                High-risk recommendations
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="ai_recommendations" value="1" class="rounded border-slate-300" @checked($plan->ai_recommendations)>
                AI-generated recommendations
            </label>
        </div>

        <div class="pt-2 border-t">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="active" value="1" class="rounded border-slate-300" @checked($plan->active)>
                Active (visible on landing page &amp; billing)
            </label>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700">Sort order</label>
            <input type="number" min="0" name="sort_order" value="{{ old('sort_order', $plan->sort_order) }}" required
                   class="mt-1 w-24 rounded-md border-slate-300 shadow-sm text-sm">
        </div>

        <button type="submit" class="bg-slate-900 text-white rounded-md px-4 py-2 text-sm font-medium">
            Save plan
        </button>
    </form>
@endsection
