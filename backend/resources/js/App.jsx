import React, { useEffect, useState } from 'react';
import { Routes, Route, useNavigate, useLocation, Link } from 'react-router-dom';
import { Frame, SkeletonPage, Banner } from '@shopify/polaris';
import Dashboard from './pages/Dashboard';
import AppImpact from './pages/AppImpact';
import Optimizations from './pages/Optimizations';
import Monitoring from './pages/Monitoring';
import AuditDetail from './pages/AuditDetail';
import Billing from './pages/Billing';
import Settings from './pages/Settings';
import RightRail from './components/RightRail';
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

/**
 * A plain top nav (design review: "left menu not a top" was about the
 * Mono direction's own choice; the Soft Plum design the app and landing
 * page both settled on uses a top bar, not Frame's own Navigation
 * sidebar - matching that, not Polaris's default chrome, is the point).
 * Frame stays, without a `navigation` prop, purely so Toast still works
 * (every page's Toast usage needs a Frame ancestor to portal into) - it
 * renders no sidebar UI on its own when navigation is omitted.
 */
function TopNav({ navItems, currentPath }) {
    return (
        <header
            style={{
                display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                padding: '14px 24px', borderBottom: '1px solid var(--p-color-border-secondary)',
                background: 'var(--p-color-bg-surface)', flexWrap: 'wrap', gap: '12px',
            }}
        >
            <Link to="/" style={{ display: 'flex', alignItems: 'center', gap: '9px', textDecoration: 'none' }}>
                <img src="/favicon.png" alt="SpeedPilot" width="26" height="26" style={{ borderRadius: '7px', display: 'block' }} />
                <span className="sp-heading" style={{ fontSize: '16px', color: 'var(--p-color-text)' }}>SpeedPilot</span>
            </Link>
            <nav style={{ display: 'flex', gap: '22px', flexWrap: 'wrap' }}>
                {navItems.map((item) => {
                    const selected = currentPath === item.path;
                    return (
                        <Link
                            key={item.label}
                            to={item.path}
                            style={{
                                fontSize: '13.5px', textDecoration: 'none',
                                color: selected ? 'var(--sp-accent)' : 'var(--p-color-text-secondary)',
                                fontWeight: selected ? 700 : 500,
                            }}
                        >
                            {item.label}
                        </Link>
                    );
                })}
            </nav>
        </header>
    );
}

export default function App() {
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

    if (billing === null) {
        return (
            <Frame>
                <SkeletonPage />
            </Frame>
        );
    }

    return (
        <PlanProvider value={{ plan: billing.plan, isFree: billing.is_free }}>
            <Frame>
                <TopNav navItems={navItems} currentPath={location.pathname} />
                {billing.is_free && (
                    <div style={{ padding: '0 var(--p-space-400)', paddingTop: 'var(--p-space-400)' }}>
                        <Banner tone="info" title="You're on the Free plan">
                            Scans and your score are always free. Upgrade to apply fixes automatically,
                            see manual-fix code, manage apps/scripts, and unlock monitoring.
                        </Banner>
                    </div>
                )}
                <div style={{ display: 'flex', gap: '28px', flexWrap: 'wrap', padding: '20px 24px', alignItems: 'flex-start' }}>
                    <div style={{ flex: '999 1 560px', minWidth: 0 }}>
                        <Routes>
                            <Route path="/" element={<Dashboard />} />
                            <Route path="/impact" element={<AppImpact />} />
                            <Route path="/optimizations" element={<Optimizations />} />
                            <Route path="/monitoring" element={<Monitoring />} />
                            <Route path="/audits/:id" element={<AuditDetail />} />
                            <Route path="/settings" element={<Settings />} />
                            <Route path="/billing" element={<Billing />} />
                        </Routes>
                    </div>
                    {/* Persistent across navigation (Layout 3's "right rail" -
                        lives here, outside <Routes>, not per-page) */}
                    <RightRail />
                </div>
            </Frame>
        </PlanProvider>
    );
}
