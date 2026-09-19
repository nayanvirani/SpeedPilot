<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\RumEvent;
use App\Models\ShopInstallation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The three mandatory GDPR webhooks - App Store review rejects submissions
 * missing these. SpeedPilot stores no customer PII (only shop-level
 * performance data), so customers/data_request and customers/redact are
 * no-ops beyond acknowledging; shop/redact actually erases the shop's data.
 */
class GdprController extends Controller
{
    public function customersDataRequest(Request $request)
    {
        Log::info('GDPR customers/data_request received', $request->all());

        return response('', 200); // no customer PII stored - nothing to return
    }

    public function customersRedact(Request $request)
    {
        Log::info('GDPR customers/redact received', $request->all());

        return response('', 200); // no customer PII stored - nothing to redact
    }

    public function shopRedact(Request $request)
    {
        $shopDomain = $request->input('shop_domain');
        $shop = ShopInstallation::where('shop_domain', $shopDomain)->first();

        if ($shop) {
            RumEvent::where('shop_installation_id', $shop->id)->delete();
            $shop->delete(); // cascades to audits/optimizations/etc. via FK constraints
        }

        return response('', 200);
    }
}
