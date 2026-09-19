import React, { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Badge, BlockStack, Card, DataTable, Page, SkeletonBodyText, Text } from '@shopify/polaris';
import { api } from '../api';

const SEVERITY_TONE = { high: 'critical', medium: 'warning', low: 'info' };

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

    const rows = (audit?.issues ?? []).map((issue) => [
        <Badge key={`sev-${issue.id}`} tone={SEVERITY_TONE[issue.severity]}>{issue.severity}</Badge>,
        issue.category,
        issue.title,
        issue.risk_tier,
        issue.fix_available ? 'Auto-fixable' : 'Recommendation only',
    ]);

    return (
        <Page title={audit ? `Audit #${audit.id}` : 'Audit'} subtitle={audit?.url ?? undefined}>
            <BlockStack gap="400">
                <Card>
                    {loading ? <SkeletonBodyText lines={2} /> : (
                        <Text as="h2" variant="headingLg">Score: {audit?.score ?? '—'}</Text>
                    )}
                </Card>
                <Card>
                    {loading ? <SkeletonBodyText lines={4} /> : (
                        <DataTable
                            columnContentTypes={['text', 'text', 'text', 'text', 'text']}
                            headings={['Severity', 'Category', 'Issue', 'Risk tier', 'Fix']}
                            rows={rows}
                        />
                    )}
                </Card>
            </BlockStack>
        </Page>
    );
}
