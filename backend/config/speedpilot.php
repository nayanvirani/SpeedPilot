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

    // Pricing tiers, agreed with the merchant-facing plan. Kept in one place so
    // PlanPolicy and BillingService never hardcode limits/prices separately.
    'plans' => [
        'free' => [
            'name' => 'Free Scan',
            'price' => 0,
            'trial_days' => 0,
            'script_rule_limit' => 0,
            'history_days' => 0,
            'auto_fixes' => false,
            'medium_risk_fixes' => false,
            'high_risk_recommendations' => false,
            'monitoring' => false,
        ],
        'starter' => [
            'name' => 'Starter',
            'price' => 19,
            'trial_days' => 7,
            'script_rule_limit' => 1,
            'history_days' => 7,
            'auto_fixes' => true,
            'auto_fix_limit' => 5,
            'medium_risk_fixes' => false,
            'high_risk_recommendations' => false,
            'monitoring' => 'basic',
        ],
        'growth' => [
            'name' => 'Growth',
            'price' => 39,
            'trial_days' => 7,
            'script_rule_limit' => null, // unlimited
            'history_days' => 30,
            'auto_fixes' => true,
            'auto_fix_limit' => null,
            'medium_risk_fixes' => true,
            'high_risk_recommendations' => false,
            'monitoring' => 'advanced',
        ],
        'pro' => [
            'name' => 'Pro',
            'price' => 79,
            'trial_days' => 7,
            'script_rule_limit' => null,
            'history_days' => 90,
            'auto_fixes' => true,
            'auto_fix_limit' => null,
            'medium_risk_fixes' => true,
            'high_risk_recommendations' => true,
            'monitoring' => 'advanced_priority',
            'ai_recommendations' => true,
        ],
    ],
];
