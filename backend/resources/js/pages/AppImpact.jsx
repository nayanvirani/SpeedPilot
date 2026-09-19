import React, { useCallback, useEffect, useState } from 'react';
import { Badge, Button, ButtonGroup, Card, DataTable, Page, SkeletonBodyText, Text } from '@shopify/polaris';
import { api } from '../api';

const IMPACT_TONE = { high: 'critical', medium: 'warning', low: 'success' };
const ACTIONS = ['active', 'disabled', 'delayed', 'excluded'];

export default function AppImpact() {
    const [appImpacts, setAppImpacts] = useState([]);
    const [loading, setLoading] = useState(true);
    const [pendingId, setPendingId] = useState(null);

    const load = useCallback(() => {
        setLoading(true);
        return api.get('/app-impacts')
            .then((res) => setAppImpacts(res.app_impacts))
            .finally(() => setLoading(false));
    }, []);

    useEffect(() => { load(); }, [load]);

    async function setStatus(id, status) {
        setPendingId(id);
        try {
            await api.patch(`/app-impacts/${id}`, { status, persist_as_rule: true });
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
        <ButtonGroup key={`actions-${impact.id}`}>
            {ACTIONS.filter((action) => action !== impact.status).map((action) => (
                <Button
                    key={action}
                    size="micro"
                    loading={pendingId === impact.id}
                    onClick={() => setStatus(impact.id, action)}
                >
                    {action}
                </Button>
            ))}
        </ButtonGroup>,
    ]);

    return (
        <Page
            title="App & Script Impact"
            subtitle={highImpactCount > 0 ? `${highImpactCount} apps are costing you significant load time` : undefined}
        >
            <Card>
                {loading ? (
                    <SkeletonBodyText lines={4} />
                ) : appImpacts.length === 0 ? (
                    <Text as="p" tone="subdued">
                        Run a scan first to see which installed apps and scripts are slowing down your store.
                    </Text>
                ) : (
                    <DataTable
                        columnContentTypes={['text', 'numeric', 'text', 'text', 'text']}
                        headings={['App / Script', 'Requests', 'Size', 'Impact', 'Action']}
                        rows={rows}
                    />
                )}
            </Card>
        </Page>
    );
}
