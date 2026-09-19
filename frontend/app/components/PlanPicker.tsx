import { Badge, BlockStack, Button, Card, InlineGrid, Text } from '@shopify/polaris';

export interface PlanRow {
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

/**
 * Shopify's hosted plan page has to open outside the embedded iframe -
 * window.open(url, '_top') is the safe way to do that; directly setting
 * window.top.location.href can throw a SecurityError under stricter
 * iframe sandboxing.
 */
export function openShopifyPricingPage(url: string) {
  window.open(url, '_top');
}

export function PlanPicker({
  plans,
  currentPlanKey,
  manageUrl,
}: {
  plans: PlanRow[];
  currentPlanKey: string | null | undefined;
  manageUrl: string | undefined;
}) {
  return (
    <InlineGrid columns={2} gap="400">
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
