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

    <div class="mt-8 bg-white rounded-lg shadow p-6 max-w-lg text-sm">
        <h2 class="font-medium mb-3">Pricing plans (config/speedpilot.php)</h2>
        <table class="w-full text-left">
            <thead class="text-slate-500">
                <tr><th class="py-1">Plan</th><th>Price</th><th>Auto-fixes</th></tr>
            </thead>
            <tbody>
                @foreach (config('speedpilot.plans') as $key => $plan)
                    <tr class="border-t">
                        <td class="py-1 capitalize">{{ $plan['name'] }}</td>
                        <td>${{ $plan['price'] }}/mo</td>
                        <td>{{ $plan['auto_fixes'] ? 'Yes' : 'No' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="text-slate-400 text-xs mt-3">Editing plan prices/features requires a code change and redeploy, not this form.</p>
    </div>
@endsection
