import React, { useEffect, useState } from 'react';
import { Routes, Route, useNavigate, useLocation } from 'react-router-dom';
import { Frame, Navigation, SkeletonPage, Banner } from '@shopify/polaris';
import Dashboard from './pages/Dashboard';
import AppImpact from './pages/AppImpact';
import Optimizations from './pages/Optimizations';
import Monitoring from './pages/Monitoring';
import AuditDetail from './pages/AuditDetail';
import Billing from './pages/Billing';
import Settings from './pages/Settings';
import { api } from './api';
import { PlanProvider } from './PlanContext';

const NAV_ITEMS = [
    { label: 'Dashboard', path: '/' },
    { label: 'App & Script Impact', path: '/impact' },
    { label: 'Optimizations', path: '/optimizations' },
    { label: 'Monitoring', path: '/monitoring' },
    { label: 'Settings', path: '/settings' },
    { label: 'Billing', path: '/billing' },
];

export default function App() {
    const navigate = useNavigate();
    const location = useLocation();

    // A shop with no paid subscription now gets the real Free tier (scan +
    // display only, see PlanPolicy::hasPaidPlan()) instead of being
    // paywalled out of the app entirely - billing is only ever null while
    // this first request is still in flight.
    const [billing, setBilling] = useState(null);

    useEffect(() => {
        api.get('/billing/current')
            .then(setBilling)
            .catch(() => setBilling({ plan: null, is_free: true }));
    }, []);

    // Monitoring and Optimizations are always empty on Free (no monitoring
    // runs, nothing was ever applied to optimize) - hidden rather than
    // shown as a permanently-blank page.
    const navItems = billing?.is_free
        ? NAV_ITEMS.filter((item) => item.path !== '/monitoring' && item.path !== '/optimizations')
        : NAV_ITEMS;

    const navigation = (
        <Navigation location={location.pathname}>
            <Navigation.Section
                items={navItems.map((item) => ({
                    label: item.label,
                    selected: location.pathname === item.path,
                    onClick: () => navigate(item.path),
                }))}
            />
        </Navigation>
    );

    if (billing === null) {
        return (
            <Frame navigation={navigation}>
                <SkeletonPage />
            </Frame>
        );
    }

    return (
        <PlanProvider value={{ plan: billing.plan, isFree: billing.is_free }}>
            <Frame navigation={navigation}>
                {billing.is_free && (
                    <div style={{ padding: '0 var(--p-space-400)', paddingTop: 'var(--p-space-400)' }}>
                        <Banner tone="info" title="You're on the Free plan">
                            Scans and your score are always free. Upgrade to apply fixes automatically,
                            see manual-fix code, manage apps/scripts, and unlock monitoring.
                        </Banner>
                    </div>
                )}
                <Routes>
                    <Route path="/" element={<Dashboard />} />
                    <Route path="/impact" element={<AppImpact />} />
                    <Route path="/optimizations" element={<Optimizations />} />
                    <Route path="/monitoring" element={<Monitoring />} />
                    <Route path="/audits/:id" element={<AuditDetail />} />
                    <Route path="/settings" element={<Settings />} />
                    <Route path="/billing" element={<Billing />} />
                </Routes>
            </Frame>
        </PlanProvider>
    );
}
