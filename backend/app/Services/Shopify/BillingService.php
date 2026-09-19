<?php

namespace App\Services\Shopify;

use App\Models\ShopInstallation;
use RuntimeException;

/**
 * Wraps Shopify's RecurringApplicationCharge GraphQL mutations. Billed entirely
 * through Shopify Billing - no Stripe, no custom payment system - since each
 * install is exactly one store with no seat/multi-store logic.
 */
class BillingService
{
    public function __construct(private readonly ShopifyGraphQLClient $client)
    {
    }

    public function createSubscription(ShopInstallation $shop, string $planKey): array
    {
        $plan = config("speedpilot.plans.{$planKey}");

        if (! $plan || $plan['price'] <= 0) {
            throw new RuntimeException("Plan [{$planKey}] is not billable (free tier or unknown).");
        }

        $data = $this->client->query(<<<'GRAPHQL'
            mutation appSubscriptionCreate(
                $name: String!, $price: Decimal!, $returnUrl: URL!, $trialDays: Int
            ) {
                appSubscriptionCreate(
                    name: $name
                    trialDays: $trialDays
                    returnUrl: $returnUrl
                    lineItems: [{
                        plan: {
                            appRecurringPricingDetails: {
                                price: { amount: $price, currencyCode: USD }
                                interval: EVERY_30_DAYS
                            }
                        }
                    }]
                ) {
                    appSubscription { id }
                    confirmationUrl
                    userErrors { field message }
                }
            }
        GRAPHQL, [
            'name' => "SpeedPilot {$plan['name']}",
            'price' => (string) $plan['price'],
            'returnUrl' => rtrim(config('shopify.frontend_url'), '/').'/billing/confirm',
            'trialDays' => $plan['trial_days'] ?? 0,
        ]);

        $result = $data['appSubscriptionCreate'] ?? [];

        if (! empty($result['userErrors'])) {
            throw new RuntimeException('Billing charge creation failed: '.json_encode($result['userErrors']));
        }

        $shop->update([
            'plan' => $planKey,
            'shopify_subscription_id' => $result['appSubscription']['id'] ?? null,
        ]);

        return $result;
    }

    public function cancelSubscription(ShopInstallation $shop): void
    {
        if (! $shop->shopify_subscription_id) {
            return;
        }

        $this->client->query(<<<'GRAPHQL'
            mutation appSubscriptionCancel($id: ID!) {
                appSubscriptionCancel(id: $id) {
                    userErrors { field message }
                }
            }
        GRAPHQL, ['id' => $shop->shopify_subscription_id]);

        $shop->update(['plan' => null, 'shopify_subscription_id' => null]);
    }
}
