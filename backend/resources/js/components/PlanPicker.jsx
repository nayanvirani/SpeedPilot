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
                                <Text as="p">✓ {plan.pages_per_scan > 1 ? 'Full-store speed scans' : 'Homepage speed scan'} & score</Text>
                                {plan.auto_fixes
                                    ? <Text as="p">✓ Automatic safe fixes</Text>
                                    : <Text as="p" tone="subdued">No automatic fixes - scan & display only</Text>}
                                {plan.script_rule_limit !== 0 && (
                                    <Text as="p">
                                        ✓ {plan.script_rule_limit ? `Up to ${plan.script_rule_limit} script rules` : 'Unlimited script rules'}
                                    </Text>
                                )}
                                {plan.medium_risk_fixes && <Text as="p">✓ Medium-risk fixes via preview theme</Text>}
                                {plan.high_risk_recommendations && <Text as="p">✓ High-risk recommendations</Text>}
                                {plan.ai_recommendations && <Text as="p">✓ AI-generated recommendations</Text>}
                                {plan.history_days > 0 && <Text as="p">✓ {plan.history_days}-day history</Text>}
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
