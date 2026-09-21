import React, { useCallback, useEffect, useState } from 'react';
import { Badge, Banner, BlockStack, Button, ButtonGroup, Card, InlineStack, Page, SkeletonBodyText, Text } from '@shopify/polaris';
import { api } from '../api';
import FixCodeViewer from '../components/FixCodeViewer';

const IMPACT_TONE = { high: 'critical', medium: 'warning', low: 'success' };
const STATUS_TONE = { active: 'success', disabled: 'critical', delayed: 'warning', excluded: 'new' };
const STATUS_LABEL = { active: 'Active', disabled: 'Disabled', delayed: 'Delayed', excluded: 'Excluded' };

const ACTION_CONFIRM = {
    disabled: (name) => `Remove ${name}'s script from your theme? This will stop it from working on your storefront until you re-enable it.`,
    delayed: (name) => `Delay ${name}'s script until the shopper first scrolls, clicks, or after 5 seconds? Some of its functionality (like a chat widget appearing instantly) may be affected.`,
};

function ImpactRow({ impact, pending, onSetStatus }) {
    return (
        <BlockStack gap="200">
            <InlineStack align="space-between" blockAlign="start" wrap>
                <BlockStack gap="050">
                    <Text as="span" fontWeight="semibold">{impact.app_name}</Text>
                    <InlineStack gap="300">
                        <Text as="span" tone="subdued">{impact.requests} requests</Text>
                        <Text as="span" tone="subdued">{Math.round(impact.size_bytes / 1024)} KB</Text>
                        <Badge tone={IMPACT_TONE[impact.impact_level]}>{impact.impact_level}</Badge>
                        <Badge tone={STATUS_TONE[impact.status] ?? 'success'}>{STATUS_LABEL[impact.status ?? 'active']}</Badge>
                    </InlineStack>
                </BlockStack>
                <ButtonGroup>
                    {['active', 'disabled', 'delayed', 'excluded']
                        .filter((action) => action !== (impact.status ?? 'active'))
                        .map((action) => (
                            <Button key={action} size="micro" loading={pending} onClick={() => onSetStatus(impact, action)}>
                                {STATUS_LABEL[action]} (auto)
                            </Button>
                        ))}
                </ButtonGroup>
            </InlineStack>
            {(impact.status ?? 'active') === 'active' && (
                <InlineStack gap="200">
                    <FixCodeViewer key={`disabled-${impact.id}`} fetchPath={`/app-impacts/${impact.id}/fix-code?action=disabled`} />
                    <FixCodeViewer key={`delayed-${impact.id}`} fetchPath={`/app-impacts/${impact.id}/fix-code?action=delayed`} />
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

    async function setStatus(impact, status) {
        if (ACTION_CONFIRM[status] && !window.confirm(ACTION_CONFIRM[status](impact.app_name))) {
            return;
        }

        setPendingId(impact.id);
        setNotice(null);
        try {
            const res = await api.patch(`/app-impacts/${impact.id}`, { status });
            if (!res.applied) {
                setNotice({
                    tone: 'warning',
                    message: res.message,
                    exemptionUrl: res.exemption_form_url,
                });
            } else {
                setNotice({ tone: 'success', message: `${impact.app_name} is now ${STATUS_LABEL[status].toLowerCase()}.` });
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
            <BlockStack gap="400">
                {notice && (
                    <Banner tone={notice.tone} onDismiss={() => setNotice(null)}>
                        <BlockStack gap="150">
                            <Text as="p">{notice.message}</Text>
                            {notice.exemptionUrl && (
                                <Text as="p">
                                    <a href={notice.exemptionUrl} target="_blank" rel="noreferrer">
                                        Submit Shopify's theme-access exemption request
                                    </a>
                                </Text>
                            )}
                        </BlockStack>
                    </Banner>
                )}
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
                                Shopify approves this app's theme-editing access. <b>Manual fix</b> shows you the exact
                                code to paste yourself right now, no approval needed. <b>Excluded</b> just stops it from
                                being flagged here - it doesn't change your storefront.
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
