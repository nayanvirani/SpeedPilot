import React, { useCallback, useEffect, useState } from 'react';
import { Badge, BlockStack, Button, Card, InlineStack, Page, SkeletonBodyText, Text, TextField } from '@shopify/polaris';
import { api } from '../api';

function statusTone(status) {
    return status === 'complete' ? 'success' : status === 'failed' ? 'critical' : 'attention';
}

function Metric({ label, value, suffix }) {
    return (
        <BlockStack gap="100">
            <Text as="span" tone="subdued">{label}</Text>
            <Text as="span" variant="headingMd">{value !== null && value !== undefined ? `${value}${suffix}` : '—'}</Text>
        </BlockStack>
    );
}

const PAGE_TYPE_LABEL = { home: 'Homepage', product: 'Product page', collection: 'Collection page', custom: 'Custom URL' };

function PageBreakdown({ pages }) {
    if (!pages || pages.length === 0) {
        return null;
    }

    return (
        <BlockStack gap="300">
            <Text as="h3" variant="headingSm">Scanned pages</Text>
            <InlineStack gap="400" wrap>
                {pages.map((page) => (
                    <BlockStack key={page.id} gap="150">
                        {page.screenshot && (
                            <img
                                src={page.screenshot}
                                alt={`Screenshot of ${page.url}`}
                                style={{ width: '120px', borderRadius: '8px', border: '1px solid var(--p-color-border-secondary)', display: 'block' }}
                            />
                        )}
                        <Text as="span" fontWeight="medium">{PAGE_TYPE_LABEL[page.page_type] ?? page.page_type}</Text>
                        <InlineStack gap="200">
                            <Text as="span" tone="subdued">{page.score ?? '—'}</Text>
                            <Badge tone={statusTone(page.status)}>{page.status}</Badge>
                        </InlineStack>
                        {page.status === 'failed' && page.error_message && (
                            <div style={{ maxWidth: '220px' }}>
                                <Text as="span" tone="critical">{page.error_message}</Text>
                            </div>
                        )}
                    </BlockStack>
                ))}
            </InlineStack>
        </BlockStack>
    );
}

export default function Dashboard() {
    const [latestAudit, setLatestAudit] = useState(null);
    const [loading, setLoading] = useState(true);
    const [scanning, setScanning] = useState(false);
    const [customUrl, setCustomUrl] = useState('');

    const load = useCallback(() => {
        setLoading(true);
        return api.get('/audits')
            .then((res) => setLatestAudit(res.audits[0] ?? null))
            .finally(() => setLoading(false));
    }, []);

    useEffect(() => { load(); }, [load]);

    async function scanNow(url) {
        setScanning(true);
        try {
            await api.post('/audits', url ? { url } : {});
            await load();
        } finally {
            setScanning(false);
        }
    }

    return (
        <Page
            title="SpeedPilot"
            primaryAction={{ content: 'Scan My Store', loading: scanning, onAction: () => scanNow() }}
        >
            <BlockStack gap="400">
                <Card>
                    <BlockStack gap="200">
                        <Text as="h3" variant="headingSm">Scan a different URL</Text>
                        <Text as="p" tone="subdued">
                            Useful when your store's own domain is password-protected (e.g. a
                            development store) - point a scan at any public storefront instead.
                        </Text>
                        <InlineStack gap="200" blockAlign="end">
                            <div style={{ flexGrow: 1 }}>
                                <TextField
                                    label="URL"
                                    labelHidden
                                    placeholder="https://example.com"
                                    value={customUrl}
                                    onChange={setCustomUrl}
                                    autoComplete="off"
                                />
                            </div>
                            <Button
                                loading={scanning}
                                disabled={!customUrl}
                                onClick={() => scanNow(customUrl)}
                            >
                                Scan this URL
                            </Button>
                        </InlineStack>
                    </BlockStack>
                </Card>
                <Card>
                    {loading ? (
                        <SkeletonBodyText lines={4} />
                    ) : !latestAudit ? (
                        <BlockStack gap="200">
                            <Text as="h2" variant="headingMd">No scans yet</Text>
                            <Text as="p" tone="subdued">
                                Run your first free audit to see your store's performance score,
                                Core Web Vitals, and which apps are slowing you down.
                            </Text>
                        </BlockStack>
                    ) : (
                        <BlockStack gap="300">
                            <InlineStack align="space-between">
                                <Text as="h2" variant="headingLg">Score: {latestAudit.score ?? '—'}</Text>
                                <Badge tone={statusTone(latestAudit.status)}>{latestAudit.status}</Badge>
                            </InlineStack>
                            <InlineStack gap="600">
                                <Metric label="LCP" value={latestAudit.lcp} suffix="s" />
                                <Metric label="INP" value={latestAudit.inp} suffix="ms" />
                                <Metric label="CLS" value={latestAudit.cls} suffix="" />
                                <Metric label="FCP" value={latestAudit.fcp} suffix="s" />
                                <Metric label="TTFB" value={latestAudit.ttfb} suffix="s" />
                            </InlineStack>
                            {latestAudit.issues && latestAudit.issues.length > 0 && (
                                <Text as="p">
                                    {latestAudit.issues.length} issues found,{' '}
                                    {latestAudit.issues.filter((i) => i.fix_available).length} auto-fixable.
                                </Text>
                            )}
                            <PageBreakdown pages={latestAudit.pages} />
                        </BlockStack>
                    )}
                </Card>
            </BlockStack>
        </Page>
    );
}
