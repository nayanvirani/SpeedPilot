import React from 'react';
import { Badge, BlockStack, Button, Card, InlineGrid, Text } from '@shopify/polaris';
import { openShopifyPricingPage } from '../api';

export default function PlanPicker({ plans, currentPlanKey, manageUrl }) {
    return (
        <InlineGrid columns={{ xs: 1, sm: plans.length > 1 ? 2 : 1 }} gap="400">
            {plans.map((plan) => {
                const isCurrent = currentPlanKey === plan.key;

                return (
                    <Card key={plan.key}>
                        <BlockStack gap="300">
                            <BlockStack gap="100">
                                <Text as="h2" variant="headingMd">
                                    {plan.name} {isCurrent && <Badge tone="success">Current plan</Badge>}
                                </Text>
                                <Text as="p" variant="heading2xl">
                                    ${plan.price}
                                    <Text as="span" tone="subdued"> /mo</Text>
                                </Text>
                            </BlockStack>
                            <BlockStack gap="150">
                                {/* The exact same 8 lines entered in Shopify's Partner Dashboard
                                    pricing config (Plan::top_features, edited via /admin/plans) -
                                    Shopify's review checks these match, so this is never derived
                                    or reworded here, only ever the literal stored text. */}
                                {plan.top_features?.length > 0 ? (
                                    plan.top_features.map((feature) => (
                                        <Text as="p" key={feature}>✓ {feature}</Text>
                                    ))
                                ) : (
                                    <Text as="p" tone="subdued">No features listed for this plan yet.</Text>
                                )}
                            </BlockStack>
                            <Button
                                variant={isCurrent ? 'secondary' : 'primary'}
                                onClick={() => manageUrl && openShopifyPricingPage(manageUrl)}
                            >
                                {isCurrent ? 'Manage plan' : 'Choose plan'}
                            </Button>
                        </BlockStack>
                    </Card>
                );
            })}
        </InlineGrid>
    );
}
