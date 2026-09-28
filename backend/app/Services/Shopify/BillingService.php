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
     * every plan change. The webhook body itself doesn't reliably carry the
     * billing period end, so an active event triggers one live query for
     * it - a cancellation deliberately does NOT (see applyPlan()'s
     * grace-period comment for why leaving it alone matters).
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

        $currentPeriodEnd = $status === 'active'
            ? $this->queryActiveSubscription($shop)['currentPeriodEnd'] ?? null
            : null;

        $this->applyPlan($shop, $chargeId, $planName, $status, $currentPeriodEnd);
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
        $active = $this->queryActiveSubscription($shop);

        if (! $active) {
            return;
        }

        $this->applyPlan(
            $shop,
            (string) $active['id'],
            strtolower((string) $active['name']),
            'active',
            $active['currentPeriodEnd'] ?? null,
        );
    }

    /**
     * @return array{id: string, name: string, status: string, currentPeriodEnd: ?string}|null
     */
    private function queryActiveSubscription(ShopInstallation $shop): ?array
    {
        $client = new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token);

        $data = $client->query(<<<'GRAPHQL'
            query activeSubscriptions {
                currentAppInstallation {
                    activeSubscriptions {
                        id
                        name
                        status
                        currentPeriodEnd
                    }
                }
            }
        GRAPHQL);

        $subscriptions = $data['currentAppInstallation']['activeSubscriptions'] ?? [];

        return collect($subscriptions)->firstWhere('status', 'ACTIVE');
    }

    /**
     * Records every status change as its own Subscription row (upgrades,
     * downgrades, cancellations each get their own history entry rather than
     * overwriting the last), and mirrors the currently-usable plan onto
     * shop_installations as a fast-path cache for PlanPolicy.
     */
    private function applyPlan(ShopInstallation $shop, string $chargeId, string $planName, string $status, ?string $currentPeriodEnd): void
    {
        $plan = $planName ? Plan::findByShopifyName($planName) : null;

        $latest = DB::transaction(function () use ($shop, $chargeId, $planName, $status, $plan, $currentPeriodEnd) {
            $attributes = [
                'plan_id' => $plan?->id,
                'shopify_plan_name' => $planName ?: null,
                'status' => $status,
            ];

            // Only overwrite when this event actually carries a real value -
            // a cancellation event passes null here on purpose, so the
            // period end already captured while this row was active stays
            // exactly as it was (that stored date is what grants the grace
            // period below, not "no expiry at all").
            if ($currentPeriodEnd !== null) {
                $attributes['current_period_end'] = $currentPeriodEnd;
            }

            Subscription::updateOrCreate(
                ['shop_installation_id' => $shop->id, 'shopify_charge_id' => $chargeId],
                $attributes,
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

            // Prefer whatever is active *right now* (order-independent when
            // a plan switch's "cancelled" and "active" events land or
            // process out of order) - falling back to the most recently
            // touched row of any status, so a cancelled-with-no-replacement
            // subscription still carries its own current_period_end forward
            // for the grace-period check below, instead of the shop looking
            // instantly unsubscribed.
            return Subscription::where('shop_installation_id', $shop->id)->where('status', 'active')->latest()->first()
                ?? Subscription::where('shop_installation_id', $shop->id)->latest()->first();
        });

        $shop->update([
            'plan' => $latest?->plan?->key,
            'shopify_subscription_id' => $latest?->shopify_charge_id,
            'plan_expires_at' => $latest?->current_period_end,
        ]);
    }
}
