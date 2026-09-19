import { Badge, BlockStack, Button, Card, InlineGrid, Page, SkeletonBodyText, Text } from '@shopify/polaris';

import { useApiData } from '~/utils/useApiData';

interface PlanRow {
  key: string;
  name: string;
  price: string;
  auto_fixes: boolean;
  medium_risk_fixes: boolean;
  high_risk_recommendations: boolean;
  ai_recommendations: boolean;
  script_rule_limit: number | null;
  history_days: number;
}

interface CurrentPlanResponse {
  plan: PlanRow | null;
  manage_url: string;
}

/**
 * Billing is Shopify Managed Pricing now - Shopify owns the actual
 * plan-picker/checkout UI, so this page is read-only: current plan, what
 * each plan includes, and a link out to Shopify's own pricing screen to
 * change it (not an in-app subscribe button).
 */
export default function BillingPage() {
  const { data: plansData, loading: plansLoading } = useApiData<{ plans: PlanRow[] }>('/billing/plans');
  const { data: currentData, loading: currentLoading } = useApiData<CurrentPlanResponse>('/billing/current');

  const plans = (plansData?.plans ?? []).filter((p) => p.key !== 'free');

  function openPricingPage() {
    if (currentData?.manage_url) {
      window.top!.location.href = currentData.manage_url;
    }
  }

  return (
    <Page title="Billing">
      <BlockStack gap="400">
        <Card>
          {currentLoading ? (
            <SkeletonBodyText lines={2} />
          ) : (
            <BlockStack gap="300">
              <Text as="h2" variant="headingMd">
                Current plan: {currentData?.plan?.name ?? 'None'}
              </Text>
              <Button onClick={openPricingPage}>Change plan</Button>
            </BlockStack>
          )}
        </Card>

        {plansLoading ? (
          <SkeletonBodyText lines={6} />
        ) : (
          <InlineGrid columns={2} gap="400">
            {plans.map((plan) => (
              <Card key={plan.key}>
                <BlockStack gap="300">
                  <BlockStack gap="100">
                    <Text as="h2" variant="headingMd">
                      {plan.name}{' '}
                      {currentData?.plan?.key === plan.key && <Badge tone="success">Current</Badge>}
                    </Text>
                    <Text as="p" variant="heading2xl">
                      ${plan.price}
                      <Text as="span" tone="subdued">
                        /mo
                      </Text>
                    </Text>
                  </BlockStack>
                  <BlockStack gap="150">
                    {plan.auto_fixes && <Text as="p">✓ Automatic safe fixes</Text>}
                    <Text as="p">
                      ✓ {plan.script_rule_limit ? `Up to ${plan.script_rule_limit} script rules` : 'Unlimited script rules'}
                    </Text>
                    {plan.medium_risk_fixes && <Text as="p">✓ Medium-risk fixes via preview theme</Text>}
                    {plan.high_risk_recommendations && <Text as="p">✓ High-risk recommendations</Text>}
                    {plan.ai_recommendations && <Text as="p">✓ AI-generated recommendations</Text>}
                    <Text as="p">✓ {plan.history_days}-day history</Text>
                  </BlockStack>
                </BlockStack>
              </Card>
            ))}
          </InlineGrid>
        )}
      </BlockStack>
    </Page>
  );
}
