import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Badge, Banner, BlockStack, Button, Card, InlineStack, Page, SkeletonBodyText, Text } from '@shopify/polaris';
import { api } from '../api';
import CategoryScores from '../components/CategoryScores';
import FixCodeViewer from '../components/FixCodeViewer';

const SEVERITY_TONE = { critical: 'critical-strong', high: 'critical', medium: 'warning', low: 'info' };
const PAGE_TYPE_LABEL = {
    home: 'Homepage', product: 'Product page', collection: 'Collection page',
    cart: 'Cart', search: 'Search', blog: 'Blog article', custom: 'Custom URL',
};

function scoreClass(score) {
    if (score === null || score === undefined) return '';
    if (score >= 90) return 'sp-score--good';
    if (score >= 50) return 'sp-score--warn';
    return 'sp-score--critical';
}

function statusTone(status) {
    return status === 'complete' ? 'success' : status === 'failed' ? 'critical' : 'attention';
}

function PageScoreCards({ pages }) {
    if (!pages || pages.length === 0) {
        return null;
    }

    return (
        <BlockStack gap="300">
            <Text as="h3" variant="headingSm"><span className="sp-heading">Score by page</span></Text>
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
                    </div>
                ))}
            </InlineStack>
        </BlockStack>
    );
}

function fixBadge(issue) {
    if (issue.risk_tier === 'safe' && issue.fix_available) {
        return { tone: 'success', label: 'Eligible for auto-fix' };
    }
    if (issue.risk_tier === 'medium' && issue.fix_available) {
        return { tone: 'attention', label: 'Fix available - needs your approval' };
    }
    return { tone: 'attention', label: 'Recommendation only' };
}

function MediumFixControls({ issue }) {
    const [preview, setPreview] = useState(null);
    const [applied, setApplied] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    async function loadPreview() {
        setLoading(true);
        setError(null);
        try {
            const res = await api.post(`/audit-issues/${issue.id}/medium-fix/preview`, {});
            setPreview(res.preview);
        } catch (e) {
            setError(e.body?.error || 'Could not preview this fix right now.');
        } finally {
            setLoading(false);
        }
    }

    async function apply() {
        setLoading(true);
        setError(null);
        try {
            const res = await api.post(`/audit-issues/${issue.id}/medium-fix/apply`, {});
            setApplied(res.optimization);
        } catch (e) {
            setError(e.body?.error || 'Could not apply this fix right now.');
        } finally {
            setLoading(false);
        }
    }

    if (applied) {
        return <Text as="p" tone="success">Applied to {applied.asset_key} - roll back anytime from the Optimizations page.</Text>;
    }

    return (
        <BlockStack gap="200">
            <Text as="span" fontWeight="medium">Auto-fix</Text>
            {!preview ? (
                <InlineStack gap="200" blockAlign="center">
                    <Button size="micro" loading={loading} onClick={loadPreview}>Preview fix</Button>
                    {error && <Text as="span" tone="critical">{error}</Text>}
                </InlineStack>
            ) : (
                <BlockStack gap="150">
                    <Text as="p" tone="subdued">
                        Minifying <b>{preview.asset_key}</b> would shrink it from {Math.round(preview.original_bytes / 1024)}KB
                        to {Math.round(preview.minified_bytes / 1024)}KB
                        ({Math.round(preview.savings_bytes / 1024)}KB saved). Nothing has been changed yet.
                    </Text>
                    <InlineStack gap="200" blockAlign="center">
                        <Button size="micro" variant="primary" loading={loading} onClick={apply}>
                            Apply this fix
                        </Button>
                        {error && <Text as="span" tone="critical">{error}</Text>}
                    </InlineStack>
                </BlockStack>
            )}
            <FixCodeViewer fetchPath={`/audit-issues/${issue.id}/fix-code`} />
        </BlockStack>
    );
}

function SafeFixControls({ issue }) {
    return (
        <BlockStack gap="200">
            <Text as="span" fontWeight="medium">Auto-fix</Text>
            <Text as="p" tone="subdued">
                SpeedPilot applies this automatically the next time it scans your store, once a target
                theme is selected on the Dashboard - no action needed here.
            </Text>
            <FixCodeViewer fetchPath={`/audit-issues/${issue.id}/fix-code`} />
        </BlockStack>
    );
}

function IssueRow({ issue, page }) {
    const [recommendation, setRecommendation] = useState(null);
    const [loadingRec, setLoadingRec] = useState(false);
    const [recError, setRecError] = useState(null);
    const badge = fixBadge(issue);

    async function getRecommendation() {
        setLoadingRec(true);
        setRecError(null);
        try {
            const res = await api.get(`/audit-issues/${issue.id}/recommendation`);
            setRecommendation(res.recommendation);
        } catch (e) {
            setRecError(e.body?.error || 'Could not load a recommendation right now.');
        } finally {
            setLoadingRec(false);
        }
    }

    return (
        <BlockStack gap="150">
            <InlineStack align="space-between" blockAlign="start">
                <InlineStack gap="200">
                    <Badge tone={SEVERITY_TONE[issue.severity]}>{issue.severity}</Badge>
                    <Text as="span" fontWeight="semibold">{issue.title}</Text>
                </InlineStack>
                <Badge tone={badge.tone}>{badge.label}</Badge>
            </InlineStack>
            {issue.description && <Text as="p" tone="subdued">{issue.description}</Text>}
            <InlineStack gap="400">
                <Text as="span" tone="subdued">Category: {issue.category}</Text>
                <Text as="span" tone="subdued">Risk tier: {issue.risk_tier}</Text>
                {page && <Text as="span" tone="subdued">Found on: {PAGE_TYPE_LABEL[page.page_type] ?? page.page_type}</Text>}
            </InlineStack>
            {issue.risk_tier === 'medium' && issue.fix_available && <MediumFixControls issue={issue} />}
            {issue.risk_tier === 'safe' && issue.fix_available && <SafeFixControls issue={issue} />}
            {recommendation ? (
                <Text as="p">✨ {recommendation}</Text>
            ) : (
                <InlineStack gap="200" blockAlign="center">
                    <Button size="micro" loading={loadingRec} onClick={getRecommendation}>
                        Get AI recommendation
                    </Button>
                    {recError && <Text as="span" tone="critical">{recError}</Text>}
                </InlineStack>
            )}
        </BlockStack>
    );
}

export default function AuditDetail() {
    const navigate = useNavigate();
    const { id } = useParams();
    const [audit, setAudit] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        setLoading(true);
        api.get(`/audits/${id}`)
            .then((res) => setAudit(res.audit))
            .finally(() => setLoading(false));
    }, [id]);

    const pagesById = Object.fromEntries((audit?.pages ?? []).map((p) => [p.id, p]));
    const issues = audit?.issues ?? [];
    const failedPages = (audit?.pages ?? []).filter((p) => p.status === 'failed' && p.error_message);

    const subtitle = audit?.url ?? (audit?.pages?.length > 1 ? `Full store scan (${audit.pages.length} pages)` : undefined);

    return (
        <Page title={audit ? `Audit #${audit.id}` : 'Audit'} subtitle={subtitle}>
            <BlockStack gap="400">
                {audit?.verifies_audit_id && audit?.verifies_audit && (
                    <Banner tone={audit.score >= audit.verifies_audit.score ? 'success' : 'warning'} title="Automatic verification re-scan">
                        <Text as="p">
                            Ran automatically after applying fixes. Score before: {audit.verifies_audit.score ?? '—'} → after: {audit.score ?? '—'}
                            {audit.score !== null && audit.verifies_audit.score !== null && (
                                <> ({audit.score - audit.verifies_audit.score >= 0 ? '+' : ''}{audit.score - audit.verifies_audit.score})</>
                            )}
                        </Text>
                    </Banner>
                )}
                {audit?.verification_audit && (
                    <Banner tone="info" title="Fixes were applied and re-verified">
                        <InlineStack gap="200" align="space-between" blockAlign="center">
                            <Text as="p">
                                A follow-up scan checked the actual effect of the fixes applied here.
                            </Text>
                            <Button onClick={() => navigate(`/audits/${audit.verification_audit.id}`)}>View verification</Button>
                        </InlineStack>
                    </Banner>
                )}
                {failedPages.map((page) => (
                    <Banner key={page.id} tone="critical" title={`Couldn't scan ${PAGE_TYPE_LABEL[page.page_type] ?? page.page_type}`}>
                        <Text as="p">{page.error_message}</Text>
                    </Banner>
                ))}
                <Card>
                    {loading ? <SkeletonBodyText lines={2} /> : (
                        <BlockStack gap="400">
                            <InlineStack gap="300" blockAlign="baseline">
                                <span className={`sp-score ${scoreClass(audit?.score)}`}>{audit?.score ?? '—'}</span>
                                <Text as="span" tone="subdued">/ 100</Text>
                            </InlineStack>
                            <CategoryScores scores={audit?.category_scores} />
                            <PageScoreCards pages={audit?.pages} />
                        </BlockStack>
                    )}
                </Card>
                <Card>
                    {loading ? <SkeletonBodyText lines={4} /> : issues.length === 0 ? (
                        <Text as="p" tone="subdued">No issues found on this scan.</Text>
                    ) : (
                        <BlockStack gap="400">
                            <Text as="p" tone="subdued">
                                "Eligible for auto-fix" means SpeedPilot can apply this safely on its
                                own - check the Optimizations page to see exactly which fixes actually
                                went through and roll any of them back.
                            </Text>
                            {issues.map((issue, i) => (
                                <React.Fragment key={issue.id}>
                                    {i > 0 && <div style={{ borderTop: '1px solid var(--p-color-border-secondary)' }} />}
                                    <IssueRow issue={issue} page={pagesById[issue.audit_page_id]} />
                                </React.Fragment>
                            ))}
                        </BlockStack>
                    )}
                </Card>
            </BlockStack>
        </Page>
    );
}
