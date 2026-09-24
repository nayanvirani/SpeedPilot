import React, { useCallback, useEffect, useState } from 'react';
import { Badge, Banner, BlockStack, Button, ButtonGroup, Card, InlineStack, Page, SkeletonBodyText, Text, Toast } from '@shopify/polaris';
import { api } from '../api';
import FixCodeViewer from '../components/FixCodeViewer';
import ThemeAccessStatus from '../components/ThemeAccessStatus';

const IMPACT_TONE = { high: 'critical', medium: 'warning', low: 'success' };
const STATUS_TONE = { active: 'success', disabled: 'critical', delayed: 'warning', excluded: 'new' };
const STATUS_LABEL = { active: 'Active', disabled: 'Disabled', delayed: 'Delayed', excluded: 'Excluded' };

// Three of these ('delayed', 'delayed_interceptor', 'stopped_content') all
// resolve to status=delayed on the server, distinguished by delay_method -
// 'theme_edit' (guaranteed, but only works when the script is found in
// theme files), 'content_replace' (guaranteed too, once SpeedPilot has
// actually observed the script as literal text in content_for_header - see
// confirm copy below), and 'interceptor' (best-effort client-side delay for
// everything else, weakest of the three).
const ACTION_META = {
    active: { label: 'Active', status: 'active' },
    disabled: { label: 'Disabled (auto)', status: 'disabled' },
    delayed: { label: 'Delayed (auto)', status: 'delayed' },
    stopped_content: { label: 'Stop (verified)', status: 'delayed', method: 'content_replace' },
    delayed_interceptor: { label: 'Advanced delay (experimental)', status: 'delayed', method: 'interceptor' },
    excluded: { label: 'Excluded', status: 'excluded' },
};

const ACTION_CONFIRM = {
    disabled: (name) => `Remove ${name}'s script from your theme? This will stop it from working on your storefront until you re-enable it.`,
    delayed: (name) => `Delay ${name}'s script until the shopper first scrolls, clicks, or after 5 seconds? Some of its functionality (like a chat widget appearing instantly) may be affected.`,
    stopped_content: (name) => `Stop ${name}'s script until the shopper first scrolls, clicks, or after 5 seconds? SpeedPilot verified this scan that ${name}'s script appears as real code in your storefront's page, so this edits your theme to neutralize it server-side, then reloads it on interaction - stronger than "Advanced delay," but still stops working if you ever uninstall SpeedPilot (the release step needs it).`,
    delayed_interceptor: (name) => `Try advanced delay for ${name}? This works by delaying resources ${name}'s own script loads dynamically after it starts - it can't guarantee delaying ${name}'s very first script tag, since that's loaded directly by Shopify and no app (including this one) can intercept it. Best-effort, not a guarantee like "Delayed (auto)." Some scripts (Shopify's own sandboxed marketing pixels) can't be delayed by any method, including this one. Requires pasting one tag near the top of your theme's <head> once (see "Advanced delay setup" above) - shared by every app, not pasted per app.`,
};

function currentActionKey(impact) {
    if ((impact.status ?? 'active') !== 'delayed') {
        return impact.status ?? 'active';
    }

    if (impact.delay_method === 'interceptor') return 'delayed_interceptor';
    if (impact.delay_method === 'content_replace') return 'stopped_content';

    return 'delayed';
}

/**
 * Every app with "Advanced delay (experimental)" enabled shares ONE watcher
 * script and ONE combined list of URLs it watches for - enabling it on a
 * 2nd, 3rd, 5th app doesn't create separate delays, it just adds that app's
 * URL to the same shared list automatically. The tag itself is identical
 * regardless of which (or how many) apps use it, so it's shown here exactly
 * once, globally - not repeated on every row, which would wrongly imply a
 * merchant needs to paste something different (or paste it again) per app.
 */
function AdvancedDelaySetup({ appImpacts }) {
    const delayed = appImpacts.filter((a) => a.status === 'delayed' && a.delay_method === 'interceptor');

    return (
        <Card>
            <BlockStack gap="200">
                <Text as="h2" variant="headingSm">Advanced delay setup</Text>
                <Text as="p" tone="subdued">
                    {delayed.length > 0
                        ? `Active for ${delayed.length} app${delayed.length === 1 ? '' : 's'}: ${delayed.map((a) => a.app_name).join(', ')}. `
                        : ''}
                    One tag, pasted once, shared by every app you turn "Advanced delay" on for below - toggling
                    it per app never needs the theme touched again.
                </Text>
                <FixCodeViewer fetchPath="/advanced-delay/fix-code" label="View advanced delay code" />
            </BlockStack>
        </Card>
    );
}

function ImpactRow({ impact, pending, onSetStatus }) {
    // Shopify's own platform scripts (Shop Pay, checkout, core analytics)
    // are injected by Shopify itself, never present as literal text in the
    // theme's own files - no app, including this one, can disable, delay,
    // or show "the code" for something that isn't in the theme to begin
    // with. Excluding it from the list is still offered.
    const actions = impact.is_platform
        ? ['active', 'excluded']
        : [
            'active', 'disabled', 'delayed', 'excluded',
            ...(impact.content_for_header_match ? ['stopped_content'] : []),
            'delayed_interceptor',
        ];
    const current = currentActionKey(impact);
    const badgeLabel = impact.status === 'delayed' && impact.delay_method === 'interceptor'
        ? 'Delayed (experimental)'
        : impact.status === 'delayed' && impact.delay_method === 'content_replace'
            ? 'Stopped (verified)'
            : STATUS_LABEL[impact.status ?? 'active'];

    return (
        <BlockStack gap="200">
            <InlineStack align="space-between" blockAlign="start" wrap>
                <BlockStack gap="050">
                    <InlineStack gap="150" blockAlign="center">
                        <Text as="span" fontWeight="semibold">{impact.app_name}</Text>
                        {impact.is_platform && <Badge tone="info">Shopify platform</Badge>}
                    </InlineStack>
                    <InlineStack gap="300">
                        <Text as="span" tone="subdued">{impact.requests} requests</Text>
                        <Text as="span" tone="subdued">{Math.round(impact.size_bytes / 1024)} KB</Text>
                        <Badge tone={IMPACT_TONE[impact.impact_level]}>{impact.impact_level}</Badge>
                        <Badge tone={STATUS_TONE[impact.status] ?? 'success'}>{badgeLabel}</Badge>
                    </InlineStack>
                    {impact.is_platform && (
                        <Text as="span" tone="subdued">
                            Loaded directly by Shopify, not an installed app - can't be disabled, delayed, or edited by any app.
                        </Text>
                    )}
                </BlockStack>
                <ButtonGroup>
                    {actions
                        .filter((action) => action !== current)
                        .map((action) => (
                            <Button key={action} size="micro" loading={pending} onClick={() => onSetStatus(impact, action)}>
                                {ACTION_META[action].label}
                            </Button>
                        ))}
                </ButtonGroup>
            </InlineStack>
            {!impact.is_platform && (impact.status ?? 'active') === 'active' && (
                <InlineStack gap="200">
                    <FixCodeViewer
                        key={`disabled-${impact.id}`}
                        fetchPath={`/app-impacts/${impact.id}/fix-code?action=disabled`}
                        label="Manual fix - view code to disable"
                    />
                    <FixCodeViewer
                        key={`delayed-${impact.id}`}
                        fetchPath={`/app-impacts/${impact.id}/fix-code?action=delayed`}
                        label="Manual fix - view code to delay"
                    />
                </InlineStack>
            )}
        </BlockStack>
    );
}

export default function AppImpact() {
    const [appImpacts, setAppImpacts] = useState([]);
    const [loading, setLoading] = useState(true);
    const [pendingId, setPendingId] = useState(null);
    const [notice, setNotice] = useState(null);

    const load = useCallback(() => {
        setLoading(true);
        return api.get('/app-impacts')
            .then((res) => setAppImpacts(res.app_impacts))
            .finally(() => setLoading(false));
    }, []);

    useEffect(() => { load(); }, [load]);

    async function setStatus(impact, actionKey) {
        if (ACTION_CONFIRM[actionKey] && !window.confirm(ACTION_CONFIRM[actionKey](impact.app_name))) {
            return;
        }

        const { status, method } = ACTION_META[actionKey];

        setPendingId(impact.id);
        setNotice(null);
        try {
            const res = await api.patch(`/app-impacts/${impact.id}`, { status, method });
            if (!res.applied) {
                setNotice({ tone: 'warning', message: res.message });
            } else {
                setNotice({ tone: 'success', message: `${impact.app_name} is now ${ACTION_META[actionKey].label.replace(' (auto)', '').toLowerCase()}.` });
            }
            await load();
        } finally {
            setPendingId(null);
        }
    }

    const highImpactCount = appImpacts.filter((a) => a.impact_level === 'high').length;

    return (
        <Page
            title="App & Script Impact"
            subtitle={highImpactCount > 0 ? `${highImpactCount} apps are costing you significant load time` : undefined}
        >
            {notice && (
                // A Toast, not just the Banner below - this list can run to
                // 15+ rows, and a banner pinned to the top of the page is
                // easy to miss entirely if the action that triggered it was
                // on a row further down and nothing visibly changes there.
                // Toast floats above the page regardless of scroll position.
                <Toast
                    content={notice.message}
                    error={notice.tone === 'warning' || notice.tone === 'critical'}
                    onDismiss={() => setNotice(null)}
                    duration={notice.tone === 'success' ? 3000 : 6000}
                />
            )}
            <BlockStack gap="400">
                {notice && (
                    <Banner tone={notice.tone} onDismiss={() => setNotice(null)}>
                        <Text as="p">{notice.message}</Text>
                    </Banner>
                )}
                <ThemeAccessStatus />
                <AdvancedDelaySetup appImpacts={appImpacts} />
                <Card>
                    {loading ? (
                        <SkeletonBodyText lines={4} />
                    ) : appImpacts.length === 0 ? (
                        <Text as="p" tone="subdued">
                            Run a scan first to see which installed apps and scripts are slowing down your store.
                        </Text>
                    ) : (
                        <BlockStack gap="400">
                            <Text as="p" tone="subdued">
                                <b>Disabled (auto)</b> and <b>Delayed (auto)</b> have SpeedPilot edit your theme directly -
                                only works when SpeedPilot can find the script in your theme's files, and once
                                Shopify approves this app's theme-editing access. <b>Stop (verified)</b> is for scripts
                                injected by another app (no theme file to edit) that SpeedPilot has actually confirmed,
                                this scan, appear as real code in your storefront's rendered page - it edits your theme
                                to neutralize that exact code server-side, then reloads it on interaction, and only
                                appears as an option once that's been confirmed. <b>Advanced delay (experimental)</b> is
                                the best-effort fallback for everything else - it watches for that app's own resources
                                loading dynamically and delays those, but some scripts (notably Shopify's own sandboxed
                                marketing pixels - Facebook, TikTok, Klarna, Affirm, and similar) can't be delayed by
                                any method, including this one, since they never appear as literal code anywhere in the
                                page to begin with. It only works once its tag is pasted near the top of your theme's
                                &lt;head&gt; (see <b>Advanced delay setup</b> above) - one tag total, not per app.{' '}
                                <b>Manual fix</b> shows
                                you the exact code to paste yourself right now, no approval needed. <b>Excluded</b> just
                                stops it from being flagged here - it doesn't change your storefront. Rows marked{' '}
                                <Badge tone="info">Shopify platform</Badge> are loaded by Shopify itself (Shop Pay,
                                checkout, core analytics), not an installed app - no app can edit these.
                            </Text>
                            {appImpacts.map((impact, i) => (
                                <React.Fragment key={impact.id}>
                                    {i > 0 && <div style={{ borderTop: '1px solid var(--p-color-border-secondary)' }} />}
                                    <ImpactRow impact={impact} pending={pendingId === impact.id} onSetStatus={setStatus} />
                                </React.Fragment>
                            ))}
                        </BlockStack>
                    )}
                </Card>
            </BlockStack>
        </Page>
    );
}
