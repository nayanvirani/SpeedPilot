<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunAuditJob;
use App\Models\AppSetting;
use App\Models\Audit;
use App\Models\ShopInstallation;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function store(Request $request)
    {
        if (AppSetting::get('maintenance_mode', '0') === '1') {
            return response()->json([
                'error' => 'SpeedPilot is undergoing maintenance. Please try again shortly.',
            ], 503);
        }

        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'url' => 'nullable|url',
        ]);

        $url = $data['url'] ?? "https://{$shop->shop_domain}";

        $audit = $shop->audits()->create(['url' => $url, 'status' => 'pending']);

        RunAuditJob::dispatch($audit->id);

        return response()->json(['audit' => $audit], 202);
    }

    public function index(Request $request)
    {
        $shop = $request->attributes->get('shop');

        return response()->json([
            'audits' => $shop->audits()->latest('id')->limit(20)->get(),
        ]);
    }

    public function show(Request $request, int $id)
    {
        $shop = $request->attributes->get('shop');

        $audit = Audit::where('shop_installation_id', $shop->id)
            ->with(['issues', 'appImpacts'])
            ->findOrFail($id);

        return response()->json(['audit' => $audit]);
    }
}
