import React, { useCallback, useEffect, useState } from 'react';
import { Badge, Banner, BlockStack, Button, ButtonGroup, Card, InlineStack, Link, Page, SkeletonBodyText, Text, Toast } from '@shopify/polaris';
import { api } from '../api';
import FixCodeViewer from '../components/FixCodeViewer';
import ThemeAccessStatus from '../components/ThemeAccessStatus';

const IMPACT_TONE = { high: 'critical', medium: 'warning', low: 'success' };
const STATUS_TONE = { active: 'success', disabled: 'critical', delayed: 'warning', excluded: 'new' };
const STATUS_LABEL = { active: 'Active', disabled: 'Disabled', delayed: 'Delayed', excluded: 'Excluded' };

// Two of these ('delayed' and 'delayed_interceptor') both resolve to
// status=delayed on the server, distinguished by delay_method - 'theme_edit'
// (guaranteed, but only works when the script is found in theme files) vs
// 'interceptor' (best-effort client-side delay for everything else, see the
// confirm copy below for exactly what that does and doesn't guarantee).
const ACTION_META = {
    active: { label: 'Active', status: 'active' },
    disabled: { label: 'Disabled (auto)', status: 'disabled' },
    delayed: { label: 'Delayed (auto)', status: 'delayed' },
    delayed_interceptor: { label: 'Advanced delay (experimental)', status: 'delayed', method: 'interceptor' },
    excluded: { label: 'Excluded', status: 'excluded' },
};

const ACTION_CONFIRM = {
    disabled: (name) => `Remove ${name}'s script from your theme? This will stop it from working on your storefront until you re-enable it.`,
    delayed: (name) => `Delay ${name}'s script until the shopper first scrolls, clicks, or after 5 seconds? Some of its functionality (like a chat widget appearing instantly) may be affected.`,
    delayed_interceptor: (name) => `Try advanced delay for ${name}? This works by delaying resources ${name}'s own script loads dynamically after it starts - it can't guarantee delaying ${name}'s very first script tag, since that's loaded directly by Shopify and no app (including this one) can intercept it. Best-effort, not a guarantee like "Delayed (auto)." Requires the "SpeedPilot Advanced Delay" app embed to be turned on in Theme Editor.`,
};

function currentActionKey(impact) {
    if ((impact.status ?? 'active') !== 'delayed') {
        return impact.status ?? 'active';
    }

    return impact.delay_method === 'interceptor' ? 'delayed_interceptor' : 'delayed';
}

/**
 * Every app with "Advanced delay (experimental)" enabled shares ONE watcher
 * script and ONE combined list of URLs it watches for - enabling it on a
 * 2nd, 3rd, 5th app doesn't create separate delays, it just adds that app's
 * URL to the same shared list automatically. This makes that aggregate
 * state visible in one place instead of merchants having to scan every
 * row's badge to piece it together themselves.
 */
function AdvancedDelaySummary({ appImpacts, embedUrl }) {
    const delayed = appImpacts.filter((a) => a.status === 'delayed' && a.delay_method === 'interceptor');

    if (delayed.length === 0) {
        return null;
    }

    return (
        <Banner tone="info" title={`Advanced delay is active for ${delayed.length} script${delayed.length === 1 ? '' : 's'}`}>
            <BlockStack gap="200">
                <Text as="p">
                    {delayed.map((a) => a.app_name).join(', ')} - all watched and delayed together by the same
                    script, kept in sync automatically as you turn this on or off per app below. No extra setup
                    needed per script.
                </Text>
                <Text as="p">
                    This only takes effect once the <b>SpeedPilot Advanced Delay</b> app embed is turned on for
                    your theme - it's what adds the script tag, not a manual theme edit.{' '}
                    {embedUrl && <Link url={embedUrl} target="_blank">Open Theme Editor &gt; App embeds</Link>}
                </Text>
            </BlockStack>
        </Banner>
    );
}

function ImpactRow({ impact, pending, onSetStatus, embedUrl }) {
    // Shopify's own platform scripts (Shop Pay, checkout, core analytics)
    // are injected by Shopify itself, never present as literal text in the
    // theme's own files - no app, including this one, can disable, delay,
    // or show "the code" for something that isn't in the theme to begin
    // with. Excluding it from the list is still offered.
    const actions = impact.is_platform
        ? ['active', 'excluded']
        : ['active', 'disabled', 'delayed', 'excluded', 'delayed_interceptor'];
    const current = currentActionKey(impact);
    const badgeLabel = impact.status === 'delayed' && impact.delay_method === 'interceptor'
        ? 'Delayed (experimental)'
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
            {!impact.is_platform && impact.status === 'delayed' && impact.delay_method === 'interceptor' && embedUrl && (
                <Text as="p" tone="subdued">
                    Turned on via <Link url={embedUrl} target="_blank">Theme Editor &gt; App embeds</Link>, not pasted code.
                </Text>
            )}
        </BlockStack>
    );
}

export default function AppImpact() {
    const [appImpacts, setAppImpacts] = useState([]);
    const [embedUrl, setEmbedUrl] = useState(null);
    const [loading, setLoading] = useState(true);
    const [pendingId, setPendingId] = useState(null);
    const [notice, setNotice] = useState(null);

    const load = useCallback(() => {
        setLoading(true);
        return api.get('/app-impacts')
            .then((res) => {
                setAppImpacts(res.app_impacts);
                setEmbedUrl(res.advanced_delay_embed_url ?? null);
            })
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
                <AdvancedDelaySummary appImpacts={appImpacts} embedUrl={embedUrl} />
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
                                Shopify approves this app's theme-editing access. <b>Advanced delay (experimental)</b> is
                                for scripts Shopify injects itself (no theme file to edit) - it watches for that app's
                                own resources loading dynamically and delays those, but can't guarantee delaying the
                                app's very first script tag, so treat it as best-effort, not a guarantee. It only works
                                once the <b>SpeedPilot Advanced Delay</b> app embed is switched on in Theme Editor - the
                                script tag is added automatically by that toggle, never by editing theme code.{' '}
                                <b>Manual fix</b> shows
                                you the exact code to paste yourself right now, no approval needed. <b>Excluded</b> just
                                stops it from being flagged here - it doesn't change your storefront. Rows marked{' '}
                                <Badge tone="info">Shopify platform</Badge> are loaded by Shopify itself (Shop Pay,
                                checkout, core analytics), not an installed app - no app can edit these.
                            </Text>
                            {appImpacts.map((impact, i) => (
                                <React.Fragment key={impact.id}>
                                    {i > 0 && <div style={{ borderTop: '1px solid var(--p-color-border-secondary)' }} />}
                                    <ImpactRow impact={impact} pending={pendingId === impact.id} onSetStatus={setStatus} embedUrl={embedUrl} />
                                </React.Fragment>
                            ))}
                        </BlockStack>
                    )}
                </Card>
            </BlockStack>
        </Page>
    );
}
