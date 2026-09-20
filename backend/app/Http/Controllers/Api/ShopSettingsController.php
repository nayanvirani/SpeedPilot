<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopInstallation;
use Illuminate\Http\Request;

class ShopSettingsController extends Controller
{
    public function show(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        return response()->json([
            'has_storefront_password' => ! empty($shop->storefront_password),
        ]);
    }

    public function updateStorefrontPassword(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'password' => 'nullable|string|max:255',
        ]);

        $shop->update(['storefront_password' => $data['password'] ?: null]);

        return response()->json(['has_storefront_password' => ! empty($shop->storefront_password)]);
    }
}
