<?php

namespace App\Services\Shopify;

use App\Models\Plan;
use App\Models\ShopInstallation;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

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
        $status = strtolower((string) ($subscription['status'] ?? 'pending'));

        if (! $chargeId) {
            return;
        }

        $this->applyPlan($shop, $chargeId, $planName, $status);
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

        if (! $active) {
            return;
        }

        $this->applyPlan($shop, (string) $active['id'], strtolower((string) $active['name']), 'active');
    }

    /**
     * Records every status change as its own Subscription row (upgrades,
     * downgrades, cancellations each get their own history entry rather than
     * overwriting the last), and mirrors the currently-active plan onto
     * shop_installations.plan as a fast-path cache for PlanPolicy - matches
     * the reference app's Subscription-history pattern exactly.
     */
    private function applyPlan(ShopInstallation $shop, string $chargeId, string $planName, string $status): void
    {
        $isActive = $status === 'active';
        $plan = $planName ? Plan::findByShopifyName($planName) : null;

        DB::transaction(function () use ($shop, $chargeId, $planName, $status, $plan) {
            Subscription::updateOrCreate(
                ['shop_installation_id' => $shop->id, 'shopify_charge_id' => $chargeId],
                [
                    'plan_id' => $plan?->id,
                    'shopify_plan_name' => $planName ?: null,
                    'status' => $status,
                ],
            );

            // Shopify only ever has one subscription active per shop - when
            // this one activates, any other row still marked active is a
            // stale prior plan (a switch that never got its own "cancelled"
            // webhook, or arrived out of order) and must not keep gating
            // the app as if it were current.
            if ($status === 'active') {
                Subscription::where('shop_installation_id', $shop->id)
                    ->where('shopify_charge_id', '!=', $chargeId)
                    ->where('status', 'active')
                    ->update(['status' => 'cancelled']);
            }
        });

        $shop->update([
            'plan' => $isActive ? $plan?->key : null,
            'shopify_subscription_id' => $isActive ? $chargeId : null,
        ]);
    }
}
