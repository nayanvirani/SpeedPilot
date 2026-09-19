@extends('layouts.admin')

@section('title', 'Settings')

@section('content')
    <h1 class="text-2xl font-semibold mb-6">Settings</h1>

    <form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white rounded-lg shadow p-6 space-y-5 max-w-lg">
        @csrf

        <label class="flex items-center gap-2 text-sm">
            <input type="hidden" name="maintenance_mode" value="0">
            <input type="checkbox" name="maintenance_mode" value="1" class="rounded border-slate-300"
                   @checked($settings['maintenance_mode'] === '1')>
            Maintenance mode (blocks new scans across all stores)
        </label>

        <div>
            <label class="block text-sm font-medium text-slate-700">Support email</label>
            <input type="email" name="support_email" value="{{ $settings['support_email'] }}"
                   class="mt-1 w-full rounded-md border-slate-300 shadow-sm text-sm">
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700">PSI daily quota override</label>
            <input type="number" min="0" name="psi_daily_quota" value="{{ $settings['psi_daily_quota'] }}"
                   placeholder="{{ config('speedpilot.psi.daily_quota') }} (default)"
                   class="mt-1 w-full rounded-md border-slate-300 shadow-sm text-sm">
        </div>

        <button type="submit" class="bg-slate-900 text-white rounded-md px-4 py-2 text-sm font-medium">
            Save settings
        </button>
    </form>

    <div class="mt-8 max-w-lg text-sm text-slate-500">
        Looking to change pricing, limits, or features? That's under
        <a href="{{ route('admin.plans.index') }}" class="text-slate-900 underline">Plans</a> now, not here.
    </div>
@endsection
