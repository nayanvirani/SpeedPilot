<?php

namespace App\Services\Shopify;

use App\Models\Plan;
use App\Models\ShopInstallation;

/**
 * Billing is Shopify Managed Pricing (plans configured in the Partner
 * Dashboard's Pricing page) - Shopify shows its own plan-picker and handles
 * checkout entirely before the merchant ever reaches the app, so this
 * service never calls appSubscriptionCreate/Cancel itself. It only reads
 * back which plan Shopify says is active and mirrors that onto the shop's
 * `plan` column, which PlanPolicy already reads from.
 */
class BillingService
{
    public function __construct(private readonly ShopifyGraphQLClient $client)
    {
    }

    /**
     * Called right after OAuth completes and from the app_subscriptions/update
     * webhook - both moments Shopify's chosen plan can change.
     */
    public function syncActivePlan(ShopInstallation $shop): void
    {
        $data = $this->client->query(<<<'GRAPHQL'
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

        $plan = $active ? Plan::findByShopifyName($active['name']) : null;

        $shop->update([
            'plan' => $plan?->key,
            'shopify_subscription_id' => $active['id'] ?? null,
        ]);
    }
}
