<?php

return [
    'scanner_url' => env('SCANNER_URL', 'http://127.0.0.1:4000'),

    'psi' => [
        'api_key' => env('PSI_API_KEY'),
        'daily_quota' => (int) env('PSI_DAILY_QUOTA', 100),
    ],

    'ai' => [
        'provider' => env('AI_PROVIDER', 'placeholder'),
        'api_key' => env('AI_PROVIDER_API_KEY'),
    ],

    // Pricing plans live in the `plans` database table (see App\Models\Plan),
    // editable from /admin/plans - not here. There is no billable "Free"
    // plan: a shop with no subscription (plan === null) still gets the free
    // audit/scan since RunAuditJob never checks plan at all; PlanPolicy's
    // null-plan fallback covers exactly what an unsubscribed shop can't do
    // (auto-fix, monitoring, etc.) until it picks a paid plan.
];
