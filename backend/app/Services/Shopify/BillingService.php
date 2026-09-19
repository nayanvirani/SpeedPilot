<?php

namespace App\Services\Shopify;

use App\Models\Plan;
use App\Models\ShopInstallation;

/**
 * Billing is Shopify Managed Pricing (plans configured in the Partner
 * Dashboard's Pricing page) - Shopify shows its own plan-picker and handles
 * checkout entirely before the merchant ever reaches the app, so this app
 * never creates or cancels a subscription itself.
 */
class BillingService
{
    /**
     * The ongoing sync path: the app_subscriptions/update webhook fires on
     * every plan change and already carries everything needed, so no
     * GraphQL round-trip is made here.
     *
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

        $this->applyPlan($shop, $status === 'active' ? $planName : null, $status === 'active' ? $chargeId : null);
    }

    /**
     * The one-time sync path: when a shop is provisioned via Token Exchange
     * (see VerifyShopifySessionToken), the merchant already picked a plan as
     * part of Shopify's managed-installation flow, but the corresponding
     * webhook may not have arrived yet (webhook delivery and this request
     * race each other). A single live query at provisioning time avoids the
     * app looking "unsubscribed" for however long that race takes.
     */
    public function syncActivePlanViaApi(ShopInstallation $shop): void
    {
        $client = new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token);

        $data = $client->query(<<<'GRAPHQL'
            query activeSubscriptions {
                currentAppInstallation {
                    activeSubscriptions {
                        id
                        name
                        status
                    }
                }
            }
        GRAPHQL);

        $subscriptions = $data['currentAppInstallation']['activeSubscriptions'] ?? [];
        $active = collect($subscriptions)->firstWhere('status', 'ACTIVE');

        $this->applyPlan(
            $shop,
            $active ? strtolower((string) $active['name']) : null,
            $active['id'] ?? null,
        );
    }

    private function applyPlan(ShopInstallation $shop, ?string $lowercasePlanName, ?string $chargeId): void
    {
        $plan = $lowercasePlanName ? Plan::findByShopifyName($lowercasePlanName) : null;

        $shop->update([
            'plan' => $plan?->key,
            'shopify_subscription_id' => $chargeId,
        ]);
    }
}
