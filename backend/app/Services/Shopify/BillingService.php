<?php

namespace App\Services\Shopify;

use App\Models\Plan;
use App\Models\ShopInstallation;

/**
 * Billing is Shopify Managed Pricing (plans configured in the Partner
 * Dashboard's Pricing page) - Shopify shows its own plan-picker and handles
 * checkout entirely before the merchant ever reaches the app, so this app
 * never creates or cancels a subscription itself. The only way it learns a
 * plan is active is the app_subscriptions/update webhook payload, handled
 * here - no GraphQL round-trip needed, the payload already has everything.
 */
class BillingService
{
    /**
     * @param  array<string, mixed>  $payload  The webhook body (or its
     *                                          nested "app_subscription" key).
     */
    public function syncFromWebhookPayload(ShopInstallation $shop, array $payload): void
    {
        $subscription = $payload['app_subscription'] ?? $payload;

        $chargeId = (string) ($subscription['admin_graphql_api_id'] ?? '');
        $planName = strtolower((string) ($subscription['name'] ?? ''));
        $status = strtolower((string) ($subscription['status'] ?? ''));

        if (! $chargeId) {
            return;
        }

        $isActive = $status === 'active';
        $plan = $isActive ? Plan::findByShopifyName($planName) : null;

        $shop->update([
            'plan' => $isActive ? $plan?->key : null,
            'shopify_subscription_id' => $isActive ? $chargeId : null,
        ]);
    }
}
