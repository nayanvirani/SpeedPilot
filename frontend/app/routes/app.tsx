import { AppProvider, Banner, Frame, Navigation, SkeletonPage } from '@shopify/polaris';
import polarisStyles from '@shopify/polaris/build/esm/styles.css?url';
import { Outlet, useLocation, useNavigate } from '@remix-run/react';
import type { LinksFunction } from '@remix-run/node';

import { useApiData } from '~/utils/useApiData';
import BillingPage from './app.billing';

export const links: LinksFunction = () => [{ rel: 'stylesheet', href: polarisStyles }];

const NAV_ITEMS = [
  { label: 'Dashboard', path: '/app' },
  { label: 'App & Script Impact', path: '/app/impact' },
  { label: 'Optimizations', path: '/app/optimizations' },
  { label: 'Monitoring', path: '/app/monitoring' },
  { label: 'Billing', path: '/app/billing' },
];

interface CurrentPlanResponse {
  plan: { key: string; name: string } | null;
}

export default function AppLayout() {
  const location = useLocation();
  const navigate = useNavigate();

  // There is no free plan - a shop with no active subscription can only
  // ever see the paywall below, regardless of which route it's on. This
  // mirrors Shopify's own Managed Pricing gate rather than relying on it
  // alone, so a cancelled-mid-session merchant is caught here too.
  const { data, loading } = useApiData<CurrentPlanResponse>('/billing/current');

  const navigation = (
    <Navigation location={location.pathname}>
      <Navigation.Section
        items={NAV_ITEMS.map((item) => ({
          label: item.label,
          selected: location.pathname === item.path,
          onClick: () => navigate(item.path),
        }))}
      />
    </Navigation>
  );

  if (loading) {
    return (
      <AppProvider i18n={{}}>
        <Frame navigation={navigation}>
          <SkeletonPage />
        </Frame>
      </AppProvider>
    );
  }

  if (!data?.plan) {
    return (
      <AppProvider i18n={{}}>
        <Frame>
          <Banner tone="info">
            Choose a plan below to start using SpeedPilot - scanning, automatic fixes, and
            monitoring are unavailable until you subscribe.
          </Banner>
          <BillingPage />
        </Frame>
      </AppProvider>
    );
  }

  return (
    <AppProvider i18n={{}}>
      <Frame navigation={navigation}>
        <Outlet />
      </Frame>
    </AppProvider>
  );
}
