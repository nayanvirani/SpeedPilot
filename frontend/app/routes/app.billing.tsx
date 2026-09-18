import { useState } from 'react';
import { Badge, BlockStack, Button, Card, InlineGrid, Page, Text } from '@shopify/polaris';

import { getSessionToken } from '~/utils/shopify.client';

const PLANS = [
  {
    key: 'starter',
    name: 'Starter',
    price: 19,
    features: ['Up to 5 safe auto-fixes', '1 script rule', 'Weekly re-scan', '7-day history'],
  },
  {
    key: 'growth',
    name: 'Growth',
    price: 39,
    recommended: true,
    features: [
      'Unlimited safe auto-fixes',
      'Unlimited script rules',
      'Medium-risk fixes via preview theme',
      'Daily monitoring',
      '30-day history',
    ],
  },
  {
    key: 'pro',
    name: 'Pro',
    price: 79,
    features: [
      'Everything in Growth',
      'High-risk recommendations',
      '90-day history + trends',
      'AI-generated recommendations',
      'Priority support',
    ],
  },
];

export default function BillingPage() {
  const [loadingPlan, setLoadingPlan] = useState<string | null>(null);

  async function subscribe(plan: string) {
    setLoadingPlan(plan);
    try {
      const token = await getSessionToken();
      const response = await fetch('/api-proxy/billing/subscribe', {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({ plan }),
      });
      const { confirmation_url } = await response.json();

      if (confirmation_url && window.shopify) {
        window.top!.location.href = confirmation_url; // Shopify billing confirmation is out-of-iframe
      }
    } finally {
      setLoadingPlan(null);
    }
  }

  return (
    <Page title="Billing">
      <InlineGrid columns={3} gap="400">
        {PLANS.map((plan) => (
          <Card key={plan.key}>
            <BlockStack gap="300">
              <BlockStack gap="100">
                <Text as="h2" variant="headingMd">
                  {plan.name} {plan.recommended && <Badge tone="success">Recommended</Badge>}
                </Text>
                <Text as="p" variant="heading2xl">
                  ${plan.price}
                  <Text as="span" tone="subdued">
                    /mo
                  </Text>
                </Text>
              </BlockStack>
              <BlockStack gap="150">
                {plan.features.map((feature) => (
                  <Text as="p" key={feature}>
                    ✓ {feature}
                  </Text>
                ))}
              </BlockStack>
              <Button
                variant="primary"
                loading={loadingPlan === plan.key}
                onClick={() => subscribe(plan.key)}
              >
                Choose {plan.name}
              </Button>
            </BlockStack>
          </Card>
        ))}
      </InlineGrid>
    </Page>
  );
}
