<?php

return [
    'api_key' => env('SHOPIFY_API_KEY'),
    'api_secret' => env('SHOPIFY_API_SECRET'),
    'api_version' => env('SHOPIFY_API_VERSION', '2025-01'),
    'scopes' => env('SHOPIFY_SCOPES', 'read_themes,write_themes,read_products'),
    'app_url' => env('SHOPIFY_APP_URL', env('APP_URL')),

    // The app's handle as it appears in admin.shopify.com/store/{shop}/apps/{app_handle}
    // and in the Managed Pricing deep link (.../charges/{app_handle}/pricing_plans).
    // Not the same as api_key - Shopify assigns this separately (visible in the
    // app's admin URL once installed; ours is "speedpilot-1").
    'app_handle' => env('SHOPIFY_APP_HANDLE', 'speedpilot-1'),

    // Our own test/dev stores, exempted from the plan-access gate
    // (ShopInstallation::hasPlanAccess()) - NOT a general bypass. Exists
    // because Shopify Managed Pricing itself can get stuck (confirmed live:
    // confirming a plan on Shopify's own pricing page silently creates no
    // subscription and fires no webhook for a specific shop+app pairing),
    // which must never be able to block us from testing the app we're
    // building. A real customer's access always still depends on a real
    // subscription - this list is comma-separated *.myshopify.com domains
    // set via env, never hardcoded here, so it can't silently apply beyond
    // whoever configures the deployment.
    'test_shops' => array_filter(explode(',', env('SPEEDPILOT_TEST_SHOPS', ''))),
];
