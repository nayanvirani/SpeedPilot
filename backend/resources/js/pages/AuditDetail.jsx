import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Badge, Banner, BlockStack, Button, Card, InlineStack, Page, SkeletonBodyText, Text } from '@shopify/polaris';
import { api } from '../api';
import CategoryScores from '../components/CategoryScores';

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

function IssueRow({ issue, page }) {
    const [recommendation, setRecommendation] = useState(null);
    const [loadingRec, setLoadingRec] = useState(false);
    const [recError, setRecError] = useState(null);

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
                <Badge tone={issue.fix_available ? 'success' : 'attention'}>
                    {issue.fix_available ? 'Eligible for auto-fix' : 'Recommendation only'}
                </Badge>
            </InlineStack>
            {issue.description && <Text as="p" tone="subdued">{issue.description}</Text>}
            <InlineStack gap="400">
                <Text as="span" tone="subdued">Category: {issue.category}</Text>
                <Text as="span" tone="subdued">Risk tier: {issue.risk_tier}</Text>
                {page && <Text as="span" tone="subdued">Found on: {PAGE_TYPE_LABEL[page.page_type] ?? page.page_type}</Text>}
            </InlineStack>
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
