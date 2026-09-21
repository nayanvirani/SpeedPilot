import React, { useEffect, useState } from 'react';
import { Routes, Route, useNavigate, useLocation } from 'react-router-dom';
import { Frame, Navigation, SkeletonPage, Banner, BlockStack } from '@shopify/polaris';
import Dashboard from './pages/Dashboard';
import AppImpact from './pages/AppImpact';
import Optimizations from './pages/Optimizations';
import Monitoring from './pages/Monitoring';
import AuditDetail from './pages/AuditDetail';
import Billing from './pages/Billing';
import Settings from './pages/Settings';
import { api } from './api';

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

    // There is no free plan - a shop with no active subscription can only
    // ever see the paywall below, regardless of which route it's on.
    const [subscriptionActive, setSubscriptionActive] = useState(null);

    useEffect(() => {
        api.get('/billing/current')
            .then((res) => setSubscriptionActive(!!res.plan))
            .catch(() => setSubscriptionActive(false));
    }, []);

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

    if (subscriptionActive === null) {
        return (
            <Frame navigation={navigation}>
                <SkeletonPage />
            </Frame>
        );
    }

    if (subscriptionActive === false) {
        return (
            <Frame>
                <BlockStack gap="400">
                    <div style={{ padding: '0 var(--p-space-400)', paddingTop: 'var(--p-space-400)' }}>
                        <Banner tone="info">
                            Choose a plan below to start using SpeedPilot - scanning, automatic
                            fixes, and monitoring are unavailable until you subscribe.
                        </Banner>
                    </div>
                    <Billing />
                </BlockStack>
            </Frame>
        );
    }

    return (
        <Frame navigation={navigation}>
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
    );
}
