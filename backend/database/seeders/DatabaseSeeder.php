<?php

namespace Database\Seeders;

use App\Models\ShopInstallation;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds one fake shop so the API is exercisable before real Shopify OAuth
     * credentials arrive - matches the "one install = one row" model, no
     * users/organizations layer to seed alongside it.
     */
    public function run(): void
    {
        ShopInstallation::updateOrCreate(
            ['shop_domain' => 'speedpilot-dev.myshopify.com'],
            [
                'access_token' => 'dev-placeholder-token',
                'scope' => config('shopify.scopes'),
                'plan' => 'growth',
                'installed_at' => now(),
            ],
        );
    }
}
