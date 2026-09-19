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

    // Billable pricing tiers - matches the exact plans/prices created in the
    // Shopify Partner Dashboard (Starter $29.99 / Pro $49.99). There is no
    // "Free" billing plan: a shop with no subscription yet (plan === null)
    // still gets the free audit/scan (RunAuditJob never checks plan at all),
    // it just can't use auto-fixes until it subscribes - see PlanPolicy's
    // NO_PLAN fallback for exactly what an unsubscribed shop can/can't do.
    'plans' => [
        'starter' => [
            'name' => 'Starter',
            'price' => 29.99,
            'trial_days' => 7,
            'script_rule_limit' => 3,
            'history_days' => 30,
            'auto_fixes' => true,
            'auto_fix_limit' => null, // unlimited
            'medium_risk_fixes' => false,
            'high_risk_recommendations' => false,
            'monitoring' => 'basic',
        ],
        'pro' => [
            'name' => 'Pro',
            'price' => 49.99,
            'trial_days' => 7,
            'script_rule_limit' => null, // unlimited
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
