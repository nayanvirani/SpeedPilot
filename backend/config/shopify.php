<?php

return [
    'api_key' => env('SHOPIFY_API_KEY'),
    'api_secret' => env('SHOPIFY_API_SECRET'),
    'api_version' => env('SHOPIFY_API_VERSION', '2025-01'),
    'scopes' => env('SHOPIFY_SCOPES', 'read_themes,write_themes,read_products'),
    'app_url' => env('SHOPIFY_APP_URL', env('APP_URL')),
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
];
