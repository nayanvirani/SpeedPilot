import React, { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Badge, BlockStack, Card, DataTable, Page, SkeletonBodyText, Text } from '@shopify/polaris';
import { api } from '../api';

const SEVERITY_TONE = { high: 'critical', medium: 'warning', low: 'info' };
const PAGE_TYPE_LABEL = { home: 'Homepage', product: 'Product page', collection: 'Collection page', custom: 'Custom URL' };

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

    const rows = (audit?.issues ?? []).map((issue) => [
        <Badge key={`sev-${issue.id}`} tone={SEVERITY_TONE[issue.severity]}>{issue.severity}</Badge>,
        issue.category,
        issue.title,
        pagesById[issue.audit_page_id] ? (PAGE_TYPE_LABEL[pagesById[issue.audit_page_id].page_type] ?? pagesById[issue.audit_page_id].page_type) : '—',
        issue.risk_tier,
        issue.fix_available ? 'Auto-fixable' : 'Recommendation only',
    ]);

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
                    {loading ? <SkeletonBodyText lines={4} /> : (
                        <DataTable
                            columnContentTypes={['text', 'text', 'text', 'text', 'text', 'text']}
                            headings={['Severity', 'Category', 'Issue', 'Page', 'Risk tier', 'Fix']}
                            rows={rows}
                        />
                    )}
                </Card>
            </BlockStack>
        </Page>
    );
}
