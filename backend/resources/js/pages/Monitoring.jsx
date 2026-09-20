import React, { useEffect, useState } from 'react';
import { Badge, BlockStack, Card, DataTable, InlineStack, Page, SkeletonBodyText, Text } from '@shopify/polaris';
import { api } from '../api';
import TrendChart from '../components/TrendChart';

// lcp is stored in seconds (matches the lab-metric convention elsewhere in
// the app), inp in milliseconds, cls unitless - per Google's published CWV
// good/needs-improvement/poor boundaries.
const CWV_THRESHOLDS = {
    lcp: { good: 2.5, poor: 4.0, suffix: 's', decimals: 2 },
    inp: { good: 200, poor: 500, suffix: 'ms', decimals: 0 },
    cls: { good: 0.1, poor: 0.25, suffix: '', decimals: 2 },
};

function ratingTone(metric, value) {
    if (value === null || value === undefined) return null;
    const t = CWV_THRESHOLDS[metric];
    if (value <= t.good) return 'success';
    if (value <= t.poor) return 'warning';
    return 'critical';
}

function ratingLabel(metric, value) {
    if (value === null || value === undefined) return null;
    const t = CWV_THRESHOLDS[metric];
    if (value <= t.good) return 'Good';
    if (value <= t.poor) return 'Needs improvement';
    return 'Poor';
}

function RumStat({ metric, label, value }) {
    const tone = ratingTone(metric, value);
    const rating = ratingLabel(metric, value);
    const t = CWV_THRESHOLDS[metric];

    return (
        <div className="sp-cwv">
            <div className="sp-cwv-label">{label}</div>
            <div className="sp-cwv-val">{value !== null && value !== undefined ? `${value.toFixed(t.decimals)}${t.suffix}` : '—'}</div>
            {tone && <Badge tone={tone}>{rating}</Badge>}
        </div>
    );
}

function RealVisitorData() {
    const [summary, setSummary] = useState(null);

    useEffect(() => {
        api.get('/rum-events/summary').then(setSummary).catch(() => setSummary(null));
    }, []);

    if (!summary) {
        return null;
    }

    return (
        <Card>
            <BlockStack gap="300">
                <BlockStack gap="100">
                    <Text as="h2" variant="headingSm">Real visitor data (field data)</Text>
                    <Text as="p" tone="subdued">
                        {summary.sample_count > 0
                            ? `75th percentile across ${summary.sample_count} real page loads from the last ${summary.window_days} days - how your actual visitors experience your store, not a single lab measurement.`
                            : `No real-visitor data collected yet. Once the SpeedPilot theme extension is enabled, real Core Web Vitals from your visitors will appear here.`}
                    </Text>
                </BlockStack>
                {summary.sample_count > 0 && (
                    <div className="sp-cwv-grid">
                        <RumStat metric="lcp" label="LCP (p75)" value={summary.p75_lcp} />
                        <RumStat metric="inp" label="INP (p75)" value={summary.p75_inp} />
                        <RumStat metric="cls" label="CLS (p75)" value={summary.p75_cls} />
                    </div>
                )}
            </BlockStack>
        </Card>
    );
}

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
                <RealVisitorData />
            </BlockStack>
        </Page>
    );
}
