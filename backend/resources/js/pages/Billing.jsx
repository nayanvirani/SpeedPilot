import React, { useEffect, useState } from 'react';
import { Page, Card, BlockStack, InlineGrid, InlineStack, Text, Banner, SkeletonBodyText } from '@shopify/polaris';
import { api } from '../api';
import PlanPicker from '../components/PlanPicker';

function UsageBar({ label, used, limit }) {
    const pct = limit ? Math.min(100, (used / limit) * 100) : 0;
    const nearLimit = limit && used / limit >= 0.9;

    return (
        <BlockStack gap="150">
            <InlineStack align="space-between">
                <Text as="span" tone="subdued">{label}</Text>
                <Text as="span" fontWeight="medium">
                    {used}{limit ? ` / ${limit}` : ' (unlimited)'}
                </Text>
            </InlineStack>
            {limit ? (
                <div style={{ height: '8px', borderRadius: '999px', background: '#f1f2f4', overflow: 'hidden' }}>
                    <div style={{
                        height: '100%',
                        width: `${Math.max(3, pct)}%`,
                        borderRadius: '999px',
                        background: nearLimit ? '#d72c0d' : '#008060',
                        transition: 'width .2s ease',
                    }} />
                </div>
            ) : (
                <div style={{ height: '8px', borderRadius: '999px', background: '#e8f5f0' }} />
            )}
        </BlockStack>
    );
}

/**
 * Billing is Shopify Managed Pricing - plans, prices and trials live in the
 * Partner Dashboard, and a merchant picks a plan on Shopify's own hosted
 * page. This page only previews those plans and links out; it never
 * creates a subscription itself (Shopify's Billing API rejects
 * appSubscriptionCreate once App Pricing is enabled).
 */
export default function Billing() {
    const [plans, setPlans] = useState(null);
    const [current, setCurrent] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        api.get('/billing/plans').then((res) => setPlans(res.plans)).catch(() => setPlans([]));
        api.get('/billing/current').then(setCurrent).catch(() => setError('Could not load billing status.'));
    }, []);

    const usage = current?.usage;

    return (
        <Page title="Billing">
            <BlockStack gap="400">
                {error && <Banner tone="critical">{error}</Banner>}

                {current?.plan && (
                    <Banner tone="success">
                        You're on the {current.plan.name} plan. Manage or change it on Shopify.
                    </Banner>
                )}

                {usage && (
                    <Card>
                        <BlockStack gap="400">
                            <Text as="h2" variant="headingMd">Usage</Text>
                            <InlineGrid columns={{ xs: 1, sm: 2 }} gap="400">
                                <UsageBar label="Script rules" used={usage.script_rules.used} limit={usage.script_rules.limit} />
                                <UsageBar label="Safe fixes applied" used={usage.auto_fixes_applied.used} limit={usage.auto_fixes_applied.limit} />
                            </InlineGrid>
                        </BlockStack>
                    </Card>
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
