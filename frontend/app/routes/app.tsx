import { AppProvider, Frame, Navigation } from '@shopify/polaris';
import polarisStyles from '@shopify/polaris/build/esm/styles.css?url';
import { Outlet, useLocation, useNavigate } from '@remix-run/react';
import type { LinksFunction } from '@remix-run/node';

export const links: LinksFunction = () => [{ rel: 'stylesheet', href: polarisStyles }];

const NAV_ITEMS = [
  { label: 'Dashboard', path: '/app' },
  { label: 'App & Script Impact', path: '/app/impact' },
  { label: 'Optimizations', path: '/app/optimizations' },
  { label: 'Monitoring', path: '/app/monitoring' },
  { label: 'Billing', path: '/app/billing' },
];

export default function AppLayout() {
  const location = useLocation();
  const navigate = useNavigate();

  return (
    <AppProvider i18n={{}}>
      <Frame
        navigation={
          <Navigation location={location.pathname}>
            <Navigation.Section
              items={NAV_ITEMS.map((item) => ({
                label: item.label,
                selected: location.pathname === item.path,
                onClick: () => navigate(item.path),
              }))}
            />
          </Navigation>
        }
      >
        <Outlet />
      </Frame>
    </AppProvider>
  );
}
