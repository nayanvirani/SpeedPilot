import React, { useEffect, useState } from 'react';
import { Page, Card, BlockStack, Text, Banner, SkeletonBodyText } from '@shopify/polaris';
import { api } from '../api';
import PlanPicker from '../components/PlanPicker';

/**
 * Billing is Shopify Managed Pricing - plans, prices and trials live in the
 * Partner Dashboard, and a merchant picks a plan on Shopify's own hosted
 * page. This page only previews those plans and links out; it never
 * creates a subscription itself.
 */
export default function Billing() {
    const [plans, setPlans] = useState(null);
    const [current, setCurrent] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        api.get('/billing/plans').then((res) => setPlans(res.plans)).catch(() => setPlans([]));
        api.get('/billing/current').then(setCurrent).catch(() => setError('Could not load billing status.'));
    }, []);

    return (
        <Page title="Billing">
            <BlockStack gap="400">
                {error && <Banner tone="critical">{error}</Banner>}

                {current?.plan && (
                    <Banner tone="success">
                        You're on the {current.plan.name} plan. Manage or change it on Shopify.
                    </Banner>
                )}

                {!plans ? (
                    <Card><SkeletonBodyText lines={6} /></Card>
                ) : (
                    <PlanPicker plans={plans} currentPlanKey={current?.plan?.key} manageUrl={current?.manage_url} />
                )}
            </BlockStack>
        </Page>
    );
}
