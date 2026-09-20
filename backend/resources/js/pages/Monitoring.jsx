import React, { useEffect, useState } from 'react';
import { Badge, BlockStack, Card, DataTable, Page, SkeletonBodyText, Text } from '@shopify/polaris';
import { api } from '../api';
import TrendChart from '../components/TrendChart';

export default function Monitoring() {
    const [runs, setRuns] = useState([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        api.get('/monitoring/trend')
            .then((res) => setRuns(res.monitoring_runs))
            .finally(() => setLoading(false));
    }, []);

    const chartPoints = runs.map((run) => ({
        score: run.audit.score,
        label: new Date(run.run_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }),
    }));

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
            <BlockStack gap="400">
                <Card>
                    {loading ? (
                        <SkeletonBodyText lines={4} />
                    ) : runs.length === 0 ? (
                        <Text as="p" tone="subdued">
                            Monitoring history will appear here once daily scans start running.
                        </Text>
                    ) : (
                        <BlockStack gap="200">
                            <Text as="h2" variant="headingSm">Score over time</Text>
                            <TrendChart points={chartPoints} />
                        </BlockStack>
                    )}
                </Card>
                {runs.length > 0 && (
                    <Card>
                        <DataTable
                            columnContentTypes={['text', 'numeric', 'text']}
                            headings={['Date', 'Score', 'Change']}
                            rows={rows}
                        />
                    </Card>
                )}
            </BlockStack>
        </Page>
    );
}
