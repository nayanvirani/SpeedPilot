<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function edit()
    {
        $settings = AppSetting::allSettings();

        return view('admin.settings', compact('settings'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'maintenance_mode' => 'nullable|boolean',
            'support_email' => 'nullable|email',
            'psi_daily_quota' => 'nullable|integer|min:0',
        ]);

        AppSetting::set('maintenance_mode', $request->boolean('maintenance_mode') ? '1' : '0');
        AppSetting::set('support_email', $data['support_email'] ?? '');
        AppSetting::set('psi_daily_quota', (string) ($data['psi_daily_quota'] ?? ''));

        return back()->with('status', 'Settings saved.');
    }
}
