import React, { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Badge, Banner, BlockStack, Button, Card, InlineStack, Page, SkeletonBodyText, Text } from '@shopify/polaris';
import { api } from '../api';
import CategoryScores from '../components/CategoryScores';

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

const SEVERITY_TONE = { critical: 'critical-strong', high: 'critical', medium: 'warning', low: 'info' };
const SEVERITY_ORDER = ['critical', 'high', 'medium', 'low'];

function IssueSeverityCounts({ issues }) {
    if (!issues || issues.length === 0) {
        return null;
    }

    const counts = issues.reduce((acc, issue) => {
        acc[issue.severity] = (acc[issue.severity] ?? 0) + 1;
        return acc;
    }, {});

    return (
        <InlineStack gap="200">
            {SEVERITY_ORDER.filter((s) => counts[s]).map((s) => (
                <Badge key={s} tone={SEVERITY_TONE[s]}>{`${counts[s]} ${s}`}</Badge>
            ))}
        </InlineStack>
    );
}

const PAGE_TYPE_LABEL = {
    home: 'Homepage', product: 'Product page', collection: 'Collection page',
    cart: 'Cart', search: 'Search', blog: 'Blog article', custom: 'Custom URL',
};

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
                            <Text as="span" fontWeight="medium">
                                {PAGE_TYPE_LABEL[page.page_type] ?? page.page_type} · {page.device === 'desktop' ? 'Desktop' : 'Mobile'}
                            </Text>
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

export default function Dashboard() {
    const navigate = useNavigate();
    const [latestAudit, setLatestAudit] = useState(null);
    const [loading, setLoading] = useState(true);
    const [scanning, setScanning] = useState(false);
    const [scanError, setScanError] = useState(null);
    const [hasTargetTheme, setHasTargetTheme] = useState(true);
    const [storefrontLocked, setStorefrontLocked] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            // The list endpoint is intentionally lightweight (no screenshots)
            // - fetch full detail for just the one audit this page actually
            // displays instead of paying for all 20 rows' worth of images.
            const [{ audits }, settings] = await Promise.all([
                api.get('/audits'),
                api.get('/settings').catch(() => ({ target_theme_id: null, storefront_locked: false })),
            ]);
            setHasTargetTheme(!!settings.target_theme_id);
            setStorefrontLocked(!!settings.storefront_locked);
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
        setScanError(null);
        try {
            await api.post('/audits');
            await load();
        } catch (e) {
            setScanError(e.body?.error || 'Could not start a scan right now.');
            if (e.body?.storefront_locked) setStorefrontLocked(true);
        } finally {
            setScanning(false);
        }
    }

    return (
        <Page
            title="SpeedPilot"
            primaryAction={{ content: 'Scan My Store', loading: scanning, disabled: storefrontLocked, onAction: scanNow }}
        >
            <BlockStack gap="400">
                {!loading && storefrontLocked && (
                    <Banner tone="critical" title="Your storefront is password-protected" action={{ content: 'Add storefront password', onAction: () => navigate('/settings') }}>
                        SpeedPilot can't reach your store's real content until it can unlock the password
                        page automatically. Add your storefront password in Settings, then scan.
                    </Banner>
                )}
                {scanError && !storefrontLocked && (
                    <Banner tone="critical" onDismiss={() => setScanError(null)}>{scanError}</Banner>
                )}
                {!loading && !hasTargetTheme && (
                    <Banner tone="info" title="No target theme set" action={{ content: 'Go to Settings', onAction: () => navigate('/settings') }}>
                        Fixes will stay recommendation-only until you choose which theme SpeedPilot applies them to.
                    </Banner>
                )}
                <Card>
                    {loading ? (
                        <SkeletonBodyText lines={4} />
                    ) : !latestAudit ? (
                        <BlockStack gap="200">
                            <Text as="h2" variant="headingMd">No scans yet</Text>
                            <Text as="p" tone="subdued">
                                {storefrontLocked
                                    ? 'Add your storefront password above, then run your first scan.'
                                    : "Run your first free audit to see your store's performance score, "
                                        + 'Core Web Vitals, and which apps are slowing you down.'}
                            </Text>
                        </BlockStack>
                    ) : latestAudit.status === 'failed' ? (
                        <BlockStack gap="200">
                            <InlineStack align="space-between" blockAlign="center">
                                <Text as="h2" variant="headingMd">Scan couldn't complete</Text>
                                <Badge tone="critical">failed</Badge>
                            </InlineStack>
                            <Text as="p" tone="subdued">
                                {latestAudit.pages?.find((p) => p.error_message)?.error_message
                                    ?? 'None of the pages in this scan could be reached. Try again, or check Settings if your store needs a storefront password.'}
                            </Text>
                            <InlineStack gap="200">
                                <Button loading={scanning} onClick={scanNow} disabled={storefrontLocked}>Retry scan</Button>
                                <Button onClick={() => navigate('/settings')}>Go to Settings</Button>
                            </InlineStack>
                        </BlockStack>
                    ) : (
                        <BlockStack gap="400">
                            <InlineStack align="space-between" blockAlign="center">
                                <InlineStack gap="300" blockAlign="baseline">
                                    <span className={`sp-score ${scoreClass(latestAudit.score)}`}>{latestAudit.score ?? '—'}</span>
                                    <Text as="span" tone="subdued">/ 100</Text>
                                </InlineStack>
                                <InlineStack gap="300" blockAlign="center">
                                    <Badge tone={statusTone(latestAudit.status)}>{latestAudit.status}</Badge>
                                    {latestAudit.status === 'complete' && (
                                        <Button onClick={() => navigate(`/audits/${latestAudit.id}`)}>
                                            View full report
                                        </Button>
                                    )}
                                </InlineStack>
                            </InlineStack>
                            <div className="sp-cwv-grid">
                                <CwvStat label="LCP" value={latestAudit.lcp} suffix="s" />
                                <CwvStat label="INP" value={latestAudit.inp} suffix="ms" />
                                <CwvStat label="CLS" value={latestAudit.cls} suffix="" />
                                <CwvStat label="FCP" value={latestAudit.fcp} suffix="s" />
                                <CwvStat label="TTFB" value={latestAudit.ttfb} suffix="s" />
                                <CwvStat label="TBT" value={latestAudit.tbt} suffix="ms" />
                                <CwvStat label="Speed Index" value={latestAudit.speed_index} suffix="s" />
                            </div>
                            <CategoryScores scores={latestAudit.category_scores} />
                            {latestAudit.issues && latestAudit.issues.length > 0 && (
                                <BlockStack gap="150">
                                    <IssueSeverityCounts issues={latestAudit.issues} />
                                    <Text as="p">
                                        {latestAudit.issues.length} issues found,{' '}
                                        {latestAudit.issues.filter((i) => i.fix_available).length} auto-fixable -
                                        see "View full report" above for details and AI recommendations.
                                    </Text>
                                </BlockStack>
                            )}
                            <InlineStack gap="200">
                                <Button onClick={() => navigate(`/audits/${latestAudit.id}`)}>Fix issues</Button>
                                <Button onClick={() => navigate('/monitoring')}>View monitoring</Button>
                            </InlineStack>
                            <PageScoreCards pages={latestAudit.pages} />
                        </BlockStack>
                    )}
                </Card>
            </BlockStack>
        </Page>
    );
}
