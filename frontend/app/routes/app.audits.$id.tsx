import { useParams } from '@remix-run/react';
import { Badge, BlockStack, Card, DataTable, Page, SkeletonBodyText, Text } from '@shopify/polaris';

import { useApiData } from '~/utils/useApiData';
import type { Audit } from '~/utils/types';

const SEVERITY_TONE = { high: 'critical', medium: 'warning', low: 'info' } as const;

export default function AuditDetail() {
  const { id } = useParams();
  const { data, loading } = useApiData<{ audit: Audit }>(`/audits/${id}`);
  const audit = data?.audit;

  const rows = (audit?.issues ?? []).map((issue) => [
    <Badge key={`sev-${issue.id}`} tone={SEVERITY_TONE[issue.severity]}>
      {issue.severity}
    </Badge>,
    issue.category,
    issue.title,
    issue.risk_tier,
    issue.fix_available ? 'Auto-fixable' : 'Recommendation only',
  ]);

  return (
    <Page title={audit ? `Audit #${audit.id}` : 'Audit'} subtitle={audit?.url ?? undefined}>
      <BlockStack gap="400">
        <Card>
          {loading ? (
            <SkeletonBodyText lines={2} />
          ) : (
            <Text as="h2" variant="headingLg">
              Score: {audit?.score ?? '—'}
            </Text>
          )}
        </Card>
        <Card>
          {loading ? (
            <SkeletonBodyText lines={4} />
          ) : (
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
