import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Badge, Banner, BlockStack, Button, Card, InlineStack, Page, Select, SkeletonBodyText, Text } from '@shopify/polaris';
import { api } from '../api';
import CategoryScores from '../components/CategoryScores';
import FixCodeViewer from '../components/FixCodeViewer';

const SEVERITY_TONE = { critical: 'critical-strong', high: 'critical', medium: 'warning', low: 'info' };
const PAGE_TYPE_LABEL = {
    home: 'Homepage', product: 'Product page', collection: 'Collection page',
    cart: 'Cart', search: 'Search', blog: 'Blog article', custom: 'Custom URL',
};
const ISSUE_CATEGORY_LABEL = { image: 'Images', js: 'JavaScript', css: 'CSS', cls: 'Layout shift (CLS)', theme: 'Theme' };
const SEVERITY_ORDER = ['critical', 'high', 'medium', 'low'];

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

// Fix Confidence: reuses the existing risk_tier the backend already computes
// (safe/medium/high) - this is just giving it the merchant-facing framing
// spec calls for, not a second classification system to keep in sync.
const FIX_CONFIDENCE = {
    safe: { icon: '🟢', label: 'Safe', tone: 'success', blurb: 'Can be applied automatically.' },
    medium: { icon: '🟡', label: 'Review', tone: 'warning', blurb: 'Requires your confirmation before it changes anything.' },
    high: { icon: '🔴', label: 'Manual', tone: 'critical', blurb: 'Recommendation only - review and apply yourself.' },
};

function fixConfidence(issue) {
    return FIX_CONFIDENCE[issue.risk_tier] ?? FIX_CONFIDENCE.high;
}

function evidenceRows(evidence) {
    if (!evidence) return [];

    const rows = [];
    if (evidence.size_bytes != null) rows.push(['Size', `${Math.round(evidence.size_bytes / 1024)} KB`]);
    if (evidence.wasted_bytes != null) rows.push(['Wasted', `${Math.round(evidence.wasted_bytes / 1024)} KB`]);
    if (evidence.displayed_width && evidence.displayed_height) {
        rows.push(['Displayed size', `${evidence.displayed_width} × ${evidence.displayed_height}px`]);
    }
    if (evidence.format) rows.push(['Format', evidence.format]);
    if (evidence.detected_as) rows.push(['Detected as', evidence.detected_as]);
    if (evidence.element) rows.push(['Element', evidence.element]);
    if (evidence.cause) rows.push(['Likely cause', evidence.cause]);
    if (evidence.shift_score != null) rows.push(['Shift score', evidence.shift_score]);
    if (evidence.wasted_ms != null) rows.push(['Blocking time', `${Math.round(evidence.wasted_ms)}ms`]);

    return rows;
}

function EvidenceCard({ meta }) {
    const evidence = meta?.evidence;
    const rows = evidenceRows(evidence);

    if (rows.length === 0 && !evidence?.estimated_impact && !meta?.recommendation) {
        return null;
    }

    const impactTone = { HIGH: 'critical', MEDIUM: 'warning', LOW: 'info' }[evidence?.estimated_impact];

    return (
        <div className="sp-evidence-card">
            <InlineStack gap="500" wrap>
                {rows.map(([label, value]) => (
                    <BlockStack gap="0" key={label}>
                        <Text as="span" tone="subdued">{label}</Text>
                        <Text as="span" fontWeight="semibold">{value}</Text>
                    </BlockStack>
                ))}
                {evidence?.estimated_impact && (
                    <BlockStack gap="0">
                        <Text as="span" tone="subdued">Estimated impact</Text>
                        <Badge tone={impactTone}>{evidence.estimated_impact}</Badge>
                    </BlockStack>
                )}
            </InlineStack>
            {meta?.recommendation && (
                <Text as="p" tone="subdued">Recommendation: {meta.recommendation}</Text>
            )}
        </div>
    );
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
    const confidence = fixConfidence(issue);

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
                <Badge tone={confidence.tone}>{`${confidence.icon} ${confidence.label}`}</Badge>
            </InlineStack>
            {issue.why && <Text as="p">{issue.why}</Text>}
            {issue.description && <Text as="p" tone="subdued">{issue.description}</Text>}
            <EvidenceCard meta={issue.meta} />
            <InlineStack gap="400">
                <Text as="span" tone="subdued">Category: {ISSUE_CATEGORY_LABEL[issue.category] ?? issue.category}</Text>
                <Text as="span" tone="subdued">{confidence.blurb}</Text>
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

function PriorityPlan({ auditId }) {
    const [plan, setPlan] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    async function load() {
        setLoading(true);
        setError(null);
        try {
            const res = await api.get(`/audits/${auditId}/priority-plan`);
            setPlan(res.plan);
        } catch (e) {
            setError(e.body?.error || 'Could not build a priority plan right now.');
        } finally {
            setLoading(false);
        }
    }

    return (
        <Card>
            <BlockStack gap="200">
                <Text as="h3" variant="headingSm"><span className="sp-heading">What to fix first</span></Text>
                {plan ? (
                    <div style={{ whiteSpace: 'pre-line' }}>
                        <Text as="p" tone="subdued">{plan}</Text>
                    </div>
                ) : (
                    <InlineStack gap="200" blockAlign="center">
                        <Button size="micro" loading={loading} onClick={load}>Get a priority plan</Button>
                        {error && <Text as="span" tone="critical">{error}</Text>}
                    </InlineStack>
                )}
            </BlockStack>
        </Card>
    );
}

function FixAllSafeIssues({ auditId, issues }) {
    const [applying, setApplying] = useState(false);
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);

    const counts = { safe: 0, medium: 0, high: 0 };
    issues.forEach((i) => { counts[i.risk_tier] = (counts[i.risk_tier] ?? 0) + 1; });
    const safeFixable = issues.filter((i) => i.risk_tier === 'safe' && i.fix_available);

    async function fixAll() {
        setApplying(true);
        setError(null);
        setResult(null);
        try {
            const res = await api.post(`/audits/${auditId}/apply-safe-fixes`, {});
            setResult(res);
        } catch (e) {
            setError(e.body?.error || 'Could not apply fixes right now.');
        } finally {
            setApplying(false);
        }
    }

    if (issues.length === 0) {
        return null;
    }

    return (
        <Card>
            <BlockStack gap="300">
                <InlineStack align="space-between" blockAlign="center" wrap>
                    <Text as="h3" variant="headingSm">
                        <span className="sp-heading">{issues.length} issue{issues.length === 1 ? '' : 's'} found</span>
                    </Text>
                    <InlineStack gap="200">
                        {counts.safe > 0 && <Badge tone="success">{`🟢 ${counts.safe} safe`}</Badge>}
                        {counts.medium > 0 && <Badge tone="warning">{`🟡 ${counts.medium} review`}</Badge>}
                        {counts.high > 0 && <Badge tone="critical">{`🔴 ${counts.high} manual`}</Badge>}
                    </InlineStack>
                </InlineStack>
                {safeFixable.length > 0 && !result && (
                    <InlineStack gap="200" blockAlign="center">
                        <Button variant="primary" loading={applying} onClick={fixAll}>
                            {applying ? 'Creating backup and applying…' : `Fix ${safeFixable.length} Safe Issue${safeFixable.length === 1 ? '' : 's'}`}
                        </Button>
                        {error && <Text as="span" tone="critical">{error}</Text>}
                    </InlineStack>
                )}
                {result && (
                    result.applied_count === 0 ? (
                        <Text as="p" tone="subdued">
                            No safe fixes could be applied automatically right now - see each issue's
                            "Manual fix" option below, or Shopify's theme-write approval may still be pending.
                        </Text>
                    ) : (
                        <BlockStack gap="100">
                            <Text as="p" tone="success">
                                ✓ Applied {result.applied_count} fix{result.applied_count === 1 ? '' : 'es'} - running verification now.
                            </Text>
                            {result.optimizations.map((o) => (
                                <Text as="span" key={o.id} tone="subdued">✓ {o.type} ({o.asset_key})</Text>
                            ))}
                        </BlockStack>
                    )
                )}
            </BlockStack>
        </Card>
    );
}

export default function AuditDetail() {
    const navigate = useNavigate();
    const { id } = useParams();
    const [audit, setAudit] = useState(null);
    const [loading, setLoading] = useState(true);
    const [categoryFilter, setCategoryFilter] = useState('all');
    const [severityFilter, setSeverityFilter] = useState('all');

    useEffect(() => {
        setLoading(true);
        api.get(`/audits/${id}`)
            .then((res) => setAudit(res.audit))
            .finally(() => setLoading(false));
    }, [id]);

    const pagesById = Object.fromEntries((audit?.pages ?? []).map((p) => [p.id, p]));
    const allIssues = audit?.issues ?? [];
    const issues = allIssues.filter((issue) => (
        (categoryFilter === 'all' || issue.category === categoryFilter)
        && (severityFilter === 'all' || issue.severity === severityFilter)
    ));
    const failedPages = (audit?.pages ?? []).filter((p) => p.status === 'failed' && p.error_message);

    const categoryOptions = [
        { label: 'All categories', value: 'all' },
        ...Array.from(new Set(allIssues.map((i) => i.category)))
            .map((c) => ({ label: ISSUE_CATEGORY_LABEL[c] ?? c, value: c })),
    ];
    const severityOptions = [
        { label: 'All severities', value: 'all' },
        ...SEVERITY_ORDER.filter((s) => allIssues.some((i) => i.severity === s)).map((s) => ({ label: s, value: s })),
    ];

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
                {!loading && allIssues.length > 0 && <FixAllSafeIssues auditId={audit.id} issues={allIssues} />}
                {!loading && allIssues.length > 0 && <PriorityPlan auditId={audit.id} />}
                <Card>
                    {loading ? <SkeletonBodyText lines={4} /> : allIssues.length === 0 ? (
                        <Text as="p" tone="subdued">No issues found on this scan.</Text>
                    ) : (
                        <BlockStack gap="400">
                            <Text as="p" tone="subdued">
                                🟢 Safe can be applied automatically. 🟡 Review needs your confirmation
                                first. 🔴 Manual is a recommendation only - check the Optimizations page
                                to see exactly which fixes went through and roll any of them back.
                            </Text>
                            <InlineStack gap="200" wrap>
                                <div style={{ minWidth: '200px' }}>
                                    <Select label="Category" labelHidden options={categoryOptions} value={categoryFilter} onChange={setCategoryFilter} />
                                </div>
                                <div style={{ minWidth: '160px' }}>
                                    <Select label="Severity" labelHidden options={severityOptions} value={severityFilter} onChange={setSeverityFilter} />
                                </div>
                                <Text as="span" tone="subdued">{issues.length} of {allIssues.length} issues</Text>
                            </InlineStack>
                            {issues.length === 0 ? (
                                <Text as="p" tone="subdued">No issues match this filter.</Text>
                            ) : issues.map((issue, i) => (
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
