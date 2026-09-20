import React, { useCallback, useEffect, useState } from 'react';
import { Badge, Banner, BlockStack, Button, ButtonGroup, Card, DataTable, Page, SkeletonBodyText, Text } from '@shopify/polaris';
import { api } from '../api';

const IMPACT_TONE = { high: 'critical', medium: 'warning', low: 'success' };
const STATUS_TONE = { active: 'success', disabled: 'critical', delayed: 'warning', excluded: 'new' };
const STATUS_LABEL = { active: 'Active', disabled: 'Disabled', delayed: 'Delayed', excluded: 'Excluded' };

const ACTION_CONFIRM = {
    disabled: (name) => `Remove ${name}'s script from your theme? This will stop it from working on your storefront until you re-enable it.`,
    delayed: (name) => `Delay ${name}'s script until the shopper first scrolls, clicks, or after 5 seconds? Some of its functionality (like a chat widget appearing instantly) may be affected.`,
};

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
                setNotice({ tone: 'warning', message: res.message });
            } else {
                setNotice({ tone: 'success', message: `${impact.app_name} is now ${STATUS_LABEL[status].toLowerCase()}.` });
            }
            await load();
        } finally {
            setPendingId(null);
        }
    }

    const highImpactCount = appImpacts.filter((a) => a.impact_level === 'high').length;

    const rows = appImpacts.map((impact) => [
        impact.app_name,
        impact.requests,
        `${Math.round(impact.size_bytes / 1024)} KB`,
        <Badge key={`impact-${impact.id}`} tone={IMPACT_TONE[impact.impact_level]}>{impact.impact_level}</Badge>,
        <Badge key={`status-${impact.id}`} tone={STATUS_TONE[impact.status] ?? 'success'}>
            {STATUS_LABEL[impact.status ?? 'active']}
        </Badge>,
        <ButtonGroup key={`actions-${impact.id}`}>
            {['active', 'disabled', 'delayed', 'excluded']
                .filter((action) => action !== (impact.status ?? 'active'))
                .map((action) => (
                    <Button
                        key={action}
                        size="micro"
                        loading={pendingId === impact.id}
                        onClick={() => setStatus(impact, action)}
                    >
                        {STATUS_LABEL[action]}
                    </Button>
                ))}
        </ButtonGroup>,
    ]);

    return (
        <Page
            title="App & Script Impact"
            subtitle={highImpactCount > 0 ? `${highImpactCount} apps are costing you significant load time` : undefined}
        >
            <BlockStack gap="400">
                {notice && (
                    <Banner tone={notice.tone} onDismiss={() => setNotice(null)}>
                        <Text as="p">{notice.message}</Text>
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
                        <BlockStack gap="300">
                            <Text as="p" tone="subdued">
                                <b>Disabled</b> removes the script from your theme entirely. <b>Delayed</b> makes it load
                                only after the shopper scrolls, clicks, or after 5 seconds. <b>Excluded</b> just stops it
                                from being flagged here - it doesn't change anything on your storefront. Both Disable and
                                Delay only work when SpeedPilot can find the script directly in your theme's files - some
                                apps inject scripts a different way that can't be edited this way, and you'll see why if so.
                            </Text>
                            <DataTable
                                columnContentTypes={['text', 'numeric', 'text', 'text', 'text', 'text']}
                                headings={['App / Script', 'Requests', 'Size', 'Impact', 'Status', 'Action']}
                                rows={rows}
                            />
                        </BlockStack>
                    )}
                </Card>
            </BlockStack>
        </Page>
    );
}
