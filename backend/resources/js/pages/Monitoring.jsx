import React, { useEffect, useState } from 'react';
import { Badge, Card, DataTable, Page, SkeletonBodyText, Text } from '@shopify/polaris';
import { api } from '../api';

export default function Monitoring() {
    const [runs, setRuns] = useState([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        api.get('/monitoring/trend')
            .then((res) => setRuns(res.monitoring_runs))
            .finally(() => setLoading(false));
    }, []);

    const rows = runs.map((run) => [
        new Date(run.run_at).toLocaleDateString(),
        run.audit.score ?? '—',
        run.trend_delta !== null ? (
            <Badge key={run.id} tone={run.trend_delta >= 0 ? 'success' : 'critical'}>
                {run.trend_delta >= 0 ? `+${run.trend_delta}` : `${run.trend_delta}`}
            </Badge>
        ) : '—',
    ]);

    return (
        <Page title="Monitoring">
            <Card>
                {loading ? (
                    <SkeletonBodyText lines={4} />
                ) : runs.length === 0 ? (
                    <Text as="p" tone="subdued">
                        Monitoring history will appear here once daily scans start running (requires a
                        Starter plan or above).
                    </Text>
                ) : (
                    <DataTable
                        columnContentTypes={['text', 'numeric', 'text']}
                        headings={['Date', 'Score', 'Change']}
                        rows={rows}
                    />
                )}
            </Card>
        </Page>
    );
}
