import React, { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Badge, BlockStack, Card, InlineStack, Page, SkeletonBodyText, Text } from '@shopify/polaris';
import { api } from '../api';

const SEVERITY_TONE = { high: 'critical', medium: 'warning', low: 'info' };
const PAGE_TYPE_LABEL = { home: 'Homepage', product: 'Product page', collection: 'Collection page', custom: 'Custom URL' };

function IssueRow({ issue, page }) {
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
        </BlockStack>
    );
}

export default function AuditDetail() {
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

    const subtitle = audit?.url ?? (audit?.pages?.length > 1 ? `Full store scan (${audit.pages.length} pages)` : undefined);

    return (
        <Page title={audit ? `Audit #${audit.id}` : 'Audit'} subtitle={subtitle}>
            <BlockStack gap="400">
                <Card>
                    {loading ? <SkeletonBodyText lines={2} /> : (
                        <Text as="h2" variant="headingLg">Score: {audit?.score ?? '—'}</Text>
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
