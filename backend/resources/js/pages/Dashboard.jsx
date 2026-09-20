import React, { useCallback, useEffect, useState } from 'react';
import { Badge, BlockStack, Button, Card, InlineStack, Page, SkeletonBodyText, Text, TextField } from '@shopify/polaris';
import { api } from '../api';

function statusTone(status) {
    return status === 'complete' ? 'success' : status === 'failed' ? 'critical' : 'attention';
}

function scoreClass(score) {
    if (score === null || score === undefined) return '';
    if (score >= 90) return 'sp-score--good';
    if (score >= 50) return 'sp-score--warn';
    return 'sp-score--critical';
}

function CwvStat({ label, value, suffix }) {
    return (
        <div className="sp-cwv">
            <div className="sp-cwv-label">{label}</div>
            <div className="sp-cwv-val">{value !== null && value !== undefined ? `${value}${suffix}` : '—'}</div>
        </div>
    );
}

const PAGE_TYPE_LABEL = { home: 'Homepage', product: 'Product page', collection: 'Collection page', custom: 'Custom URL' };

function PageScoreCards({ pages }) {
    if (!pages || pages.length === 0) {
        return null;
    }

    return (
        <BlockStack gap="300">
            <Text as="h3" variant="headingSm" fontWeight="semibold">
                <span className="sp-heading">Score by page</span>
            </Text>
            <InlineStack gap="300" wrap>
                {pages.map((page) => (
                    <div key={page.id} className="sp-page-card">
                        {page.screenshot && <img src={page.screenshot} alt={`Screenshot of ${page.url}`} className="sp-page-thumb" />}
                        <InlineStack align="space-between" blockAlign="center">
                            <Text as="span" fontWeight="medium">{PAGE_TYPE_LABEL[page.page_type] ?? page.page_type}</Text>
                            <Badge tone={statusTone(page.status)}>{page.status}</Badge>
                        </InlineStack>
                        <span className={`sp-score ${scoreClass(page.score)}`} style={{ fontSize: '28px' }}>
                            {page.score ?? '—'}
                        </span>
                        {page.status === 'failed' && page.error_message && (
                            <Text as="span" tone="critical">{page.error_message}</Text>
                        )}
                    </div>
                ))}
            </InlineStack>
        </BlockStack>
    );
}

function StorefrontPasswordSettings() {
    const [hasPassword, setHasPassword] = useState(null);
    const [value, setValue] = useState('');
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);

    useEffect(() => {
        api.get('/settings').then((res) => setHasPassword(res.has_storefront_password)).catch(() => setHasPassword(false));
    }, []);

    async function save() {
        setSaving(true);
        setSaved(false);
        try {
            const res = await api.put('/settings/storefront-password', { password: value });
            setHasPassword(res.has_storefront_password);
            setValue('');
            setSaved(true);
        } finally {
            setSaving(false);
        }
    }

    if (hasPassword === null) {
        return null;
    }

    return (
        <Card>
            <BlockStack gap="200">
                <Text as="h3" variant="headingSm"><span className="sp-heading">Storefront password</span></Text>
                <Text as="p" tone="subdued">
                    {hasPassword
                        ? "A password is saved - SpeedPilot unlocks your store automatically before every scan."
                        : "If your store has Shopify's storefront password enabled (every dev store does by default), scans can't reach real content without it."}
                </Text>
                <InlineStack gap="200" blockAlign="end">
                    <div style={{ flexGrow: 1, maxWidth: '280px' }}>
                        <TextField
                            label="Password"
                            labelHidden
                            type="password"
                            placeholder={hasPassword ? 'Update password' : 'Storefront password'}
                            value={value}
                            onChange={(v) => { setValue(v); setSaved(false); }}
                            autoComplete="off"
                        />
                    </div>
                    <Button loading={saving} disabled={!value} onClick={save}>Save</Button>
                    {saved && <Text as="span" tone="success">Saved</Text>}
                </InlineStack>
            </BlockStack>
        </Card>
    );
}

export default function Dashboard() {
    const [latestAudit, setLatestAudit] = useState(null);
    const [loading, setLoading] = useState(true);
    const [scanning, setScanning] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            // The list endpoint is intentionally lightweight (no screenshots)
            // - fetch full detail for just the one audit this page actually
            // displays instead of paying for all 20 rows' worth of images.
            const { audits } = await api.get('/audits');
            if (!audits[0]) {
                setLatestAudit(null);
                return;
            }
            const { audit } = await api.get(`/audits/${audits[0].id}`);
            setLatestAudit(audit);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    async function scanNow() {
        setScanning(true);
        try {
            await api.post('/audits');
            await load();
        } finally {
            setScanning(false);
        }
    }

    return (
        <Page
            title="SpeedPilot"
            primaryAction={{ content: 'Scan My Store', loading: scanning, onAction: scanNow }}
        >
            <BlockStack gap="400">
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
                        <BlockStack gap="400">
                            <InlineStack align="space-between" blockAlign="center">
                                <InlineStack gap="300" blockAlign="baseline">
                                    <span className={`sp-score ${scoreClass(latestAudit.score)}`}>{latestAudit.score ?? '—'}</span>
                                    <Text as="span" tone="subdued">/ 100</Text>
                                </InlineStack>
                                <Badge tone={statusTone(latestAudit.status)}>{latestAudit.status}</Badge>
                            </InlineStack>
                            <div className="sp-cwv-grid">
                                <CwvStat label="LCP" value={latestAudit.lcp} suffix="s" />
                                <CwvStat label="INP" value={latestAudit.inp} suffix="ms" />
                                <CwvStat label="CLS" value={latestAudit.cls} suffix="" />
                                <CwvStat label="FCP" value={latestAudit.fcp} suffix="s" />
                                <CwvStat label="TTFB" value={latestAudit.ttfb} suffix="s" />
                            </div>
                            {latestAudit.issues && latestAudit.issues.length > 0 && (
                                <Text as="p">
                                    {latestAudit.issues.length} issues found,{' '}
                                    {latestAudit.issues.filter((i) => i.fix_available).length} auto-fixable.
                                </Text>
                            )}
                            <PageScoreCards pages={latestAudit.pages} />
                        </BlockStack>
                    )}
                </Card>
                <StorefrontPasswordSettings />
            </BlockStack>
        </Page>
    );
}
