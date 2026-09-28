import React, { useEffect, useState } from 'react';
import { Badge, BlockStack, Button, Card, DataTable, InlineStack, Page, SkeletonBodyText, Tabs, Text } from '@shopify/polaris';
import { api } from '../api';
import TrendChart from '../components/TrendChart';

const PAGE_TYPE_LABEL = {
    home: 'Homepage', product: 'Product page', collection: 'Collection page',
    cart: 'Cart', search: 'Search', blog: 'Blog article', custom: 'Custom URL',
};

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

function PageTypeTrends() {
    const [series, setSeries] = useState(null);
    const [selected, setSelected] = useState(0);

    useEffect(() => {
        api.get('/monitoring/page-trend').then(setSeries).catch(() => setSeries(null));
    }, []);

    if (!series || series.page_types.length === 0) {
        return null;
    }

    const tabs = series.page_types.map((t) => ({ id: t, content: PAGE_TYPE_LABEL[t] ?? t }));
    const activeType = series.page_types[selected];
    const points = (series.series[activeType] ?? []).map((p) => ({
        score: p.score,
        label: new Date(p.created_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }),
    }));

    return (
        <Card>
            <BlockStack gap="200">
                <Text as="h2" variant="headingSm">Score by page type</Text>
                <Tabs tabs={tabs} selected={selected} onSelect={setSelected} />
                <TrendChart points={points} />
            </BlockStack>
        </Card>
    );
}

function NewScriptSuggestedAction({ script, pendingKey, onAction }) {
    if (!script.app_impact_id) {
        return (
            <Text as="span" tone="subdued">{script.app_name}</Text>
        );
    }

    const busy = pendingKey === `${script.app_impact_id}-disabled` || pendingKey === `${script.app_impact_id}-delayed`;

    return (
        <InlineStack gap="150" blockAlign="center">
            <Text as="span">{script.app_name}</Text>
            <Button
                size="micro"
                loading={pendingKey === `${script.app_impact_id}-disabled`}
                disabled={busy && pendingKey !== `${script.app_impact_id}-disabled`}
                onClick={() => onAction(script, 'disabled')}
            >
                Disable
            </Button>
            <Button
                size="micro"
                loading={pendingKey === `${script.app_impact_id}-delayed`}
                disabled={busy && pendingKey !== `${script.app_impact_id}-delayed`}
                onClick={() => onAction(script, 'delayed')}
            >
                Delay
            </Button>
        </InlineStack>
    );
}

function WhatChanged({ diff }) {
    const [pendingKey, setPendingKey] = useState(null);
    const [result, setResult] = useState({});

    if (!diff) {
        return null;
    }

    const newScripts = diff.new_third_party_scripts ?? [];
    const newIssues = diff.new_issues ?? [];
    const droppedPages = (diff.page_type_deltas ?? []).filter((p) => p.delta !== null && p.delta < 0);
    const nothingChanged = newScripts.length === 0 && newIssues.length === 0 && droppedPages.length === 0;

    async function applyAction(script, status) {
        const confirmMsg = status === 'disabled'
            ? `Disable ${script.app_name}? This stops it from loading on your storefront - only its own admin can re-enable the app itself, but you can switch this back to "Active" any time from App & Script Impact.`
            : `Delay ${script.app_name}? This is a suggested action, not a guarantee - see App & Script Impact for the full explanation of how delaying works.`;

        if (!window.confirm(confirmMsg)) {
            return;
        }

        const key = `${script.app_impact_id}-${status}`;
        setPendingKey(key);
        try {
            await api.patch(`/app-impacts/${script.app_impact_id}`, { status });
            setResult((r) => ({ ...r, [script.app_impact_id]: status }));
        } catch (e) {
            setResult((r) => ({ ...r, [script.app_impact_id]: `error:${e.body?.error || 'Could not apply this right now.'}` }));
        } finally {
            setPendingKey(null);
        }
    }

    return (
        <Card>
            <BlockStack gap="200">
                <Text as="h2" variant="headingSm">What changed since the last scan</Text>
                {nothingChanged && (
                    <Text as="p" tone="subdued">No new scripts, issues, or page-level drops since the previous scan.</Text>
                )}
                {newScripts.length > 0 && (
                    <BlockStack gap="150">
                        <Text as="p" tone="subdued">
                            New script(s) detected since the last scan (a possible contributor, not a confirmed cause):
                        </Text>
                        {newScripts.map((s) => {
                            const appliedStatus = result[s.app_impact_id];

                            if (appliedStatus && !appliedStatus.startsWith('error:')) {
                                return (
                                    <Text as="p" key={s.app_name} tone="success">
                                        {s.app_name}: set to {appliedStatus} - view or roll back on App & Script Impact.
                                    </Text>
                                );
                            }

                            return (
                                <BlockStack gap="050" key={s.app_name}>
                                    <NewScriptSuggestedAction script={s} pendingKey={pendingKey} onAction={applyAction} />
                                    {appliedStatus?.startsWith('error:') && (
                                        <Text as="span" tone="critical">{appliedStatus.slice(6)}</Text>
                                    )}
                                </BlockStack>
                            );
                        })}
                    </BlockStack>
                )}
                {newIssues.length > 0 && (
                    <Text as="p">{newIssues.length} new issue(s): {newIssues.map((i) => i.title).join('; ')}</Text>
                )}
                {droppedPages.length > 0 && (
                    <InlineStack gap="200">
                        {droppedPages.map((p) => (
                            <Badge key={p.page_type} tone="critical">
                                {`${PAGE_TYPE_LABEL[p.page_type] ?? p.page_type}: ${p.previous_score} → ${p.current_score}`}
                            </Badge>
                        ))}
                    </InlineStack>
                )}
            </BlockStack>
        </Card>
    );
}

function MonthlyReport() {
    const [month, setMonth] = useState(() => new Date().toISOString().slice(0, 7));
    const [report, setReport] = useState(null);

    useEffect(() => {
        api.get(`/monitoring/report?month=${month}`).then(setReport).catch(() => setReport(null));
    }, [month]);

    return (
        <Card>
            <BlockStack gap="300">
                <InlineStack align="space-between" blockAlign="center">
                    <Text as="h2" variant="headingSm">Monthly report</Text>
                    <input
                        type="month"
                        value={month}
                        onChange={(e) => setMonth(e.target.value)}
                        style={{ border: '1px solid var(--p-color-border-secondary)', borderRadius: '6px', padding: '4px 8px' }}
                    />
                </InlineStack>
                {!report ? (
                    <SkeletonBodyText lines={3} />
                ) : !report.has_data ? (
                    <Text as="p" tone="subdued">No completed scans for this month.</Text>
                ) : (
                    <BlockStack gap="150">
                        <Text as="p">
                            Score: {report.score.before} → {report.score.after}{' '}
                            ({report.score.delta >= 0 ? `+${report.score.delta}` : report.score.delta})
                        </Text>
                        {report.metrics.lcp?.previous !== null && report.metrics.lcp?.current !== null && (
                            <Text as="p">LCP: {report.metrics.lcp.previous.toFixed(2)}s → {report.metrics.lcp.current.toFixed(2)}s</Text>
                        )}
                        {report.metrics.inp?.previous !== null && report.metrics.inp?.current !== null && (
                            <Text as="p">INP: {Math.round(report.metrics.inp.previous)}ms → {Math.round(report.metrics.inp.current)}ms</Text>
                        )}
                        <Text as="p">{report.issues_resolved.length} issue(s) resolved, {report.issues_new.length} new</Text>
                        <Text as="p">{report.regressions.length} regression(s) detected, {report.optimizations_applied.length} optimization(s) applied</Text>
                    </BlockStack>
                )}
            </BlockStack>
        </Card>
    );
}

function formatDollars(value) {
    return `$${Math.abs(value).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
}

/**
 * A running total across every recorded run since monitoring began, not
 * just the Dashboard's one-time before/after snapshot - each run's own
 * estimate (consecutive-run delta) nets together into "what's changed
 * overall", positive runs offsetting negative ones honestly rather than
 * only ever showing improvements.
 */
function RoiLedger({ runs }) {
    const withImpact = runs.filter((r) => r.revenue_impact && Math.abs(r.revenue_impact.conversion_change_pct) >= 0.1);

    if (withImpact.length === 0) {
        return null;
    }

    const totalPct = withImpact.reduce((sum, r) => sum + r.revenue_impact.conversion_change_pct, 0);
    const hasDollar = withImpact.every((r) => r.revenue_impact.monthly_revenue_low !== null);
    const totalLow = hasDollar ? withImpact.reduce((sum, r) => sum + r.revenue_impact.monthly_revenue_low, 0) : null;
    const totalHigh = hasDollar ? withImpact.reduce((sum, r) => sum + r.revenue_impact.monthly_revenue_high, 0) : null;
    const improved = totalPct > 0;

    return (
        <Card>
            <BlockStack gap="150">
                <Text as="h2" variant="headingSm">ROI ledger</Text>
                <Text as="p" tone="subdued">
                    Net estimated conversion impact across {withImpact.length} recorded run{withImpact.length === 1 ? '' : 's'} since
                    monitoring began - improvements and regressions netted together, not just the best moment.
                </Text>
                <Text as="p">
                    Total: <Text as="span" fontWeight="semibold" tone={improved ? 'success' : 'critical'}>
                        {improved ? '+' : ''}{totalPct.toFixed(1)}%
                    </Text>
                    {hasDollar && (
                        <> (roughly {formatDollars(Math.min(totalLow, totalHigh))}–{formatDollars(Math.max(totalLow, totalHigh))}/month)</>
                    )}
                </Text>
                <Text as="p" tone="subdued">Industry-average estimate, not a guarantee - see the Dashboard for methodology.</Text>
            </BlockStack>
        </Card>
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
                <WhatChanged diff={runs[runs.length - 1]?.diff_summary} />
                <RoiLedger runs={runs} />
                <PageTypeTrends />
                <MonthlyReport />
                <RealVisitorData />
            </BlockStack>
        </Page>
    );
}
