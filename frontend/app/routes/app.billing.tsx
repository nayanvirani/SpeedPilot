import { Banner, BlockStack, Page, SkeletonBodyText } from '@shopify/polaris';

import { PlanPicker, type PlanRow } from '~/components/PlanPicker';
import { useApiData } from '~/utils/useApiData';

interface CurrentPlanResponse {
  plan: PlanRow | null;
  manage_url: string;
}

/**
 * Billing is Shopify Managed Pricing - Shopify owns the actual
 * plan-picker/checkout UI, so this page is read-only: current plan, what
 * each plan includes, and a link out to Shopify's own pricing screen to
 * change it (not an in-app subscribe button).
 */
export default function BillingPage() {
  const { data: plansData, loading: plansLoading, error: plansError, refetch: refetchPlans } =
    useApiData<{ plans: PlanRow[] }>('/billing/plans');
  const { data: currentData, loading: currentLoading, error: currentError, refetch: refetchCurrent } =
    useApiData<CurrentPlanResponse>('/billing/current');

  const error = plansError ?? currentError;
  const loading = plansLoading || currentLoading;

  return (
    <Page title="Billing">
      <BlockStack gap="400">
        {error && (
          <Banner
            tone="critical"
            title="Couldn't load plans"
            action={{
              content: 'Retry',
              onAction: () => {
                refetchPlans();
                refetchCurrent();
              },
            }}
          >
            {error}
          </Banner>
        )}

        {!currentLoading && currentData?.plan && (
          <Banner tone="success">
            You're on the {currentData.plan.name} plan. Manage or change it on Shopify.
          </Banner>
        )}

        {loading ? (
          <SkeletonBodyText lines={6} />
        ) : (
          !error && (
            <PlanPicker
              plans={plansData?.plans ?? []}
              currentPlanKey={currentData?.plan?.key}
              manageUrl={currentData?.manage_url}
            />
          )
        )}
      </BlockStack>
    </Page>
  );
}
