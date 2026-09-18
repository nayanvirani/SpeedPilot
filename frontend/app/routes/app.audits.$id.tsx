import type { LoaderFunctionArgs } from '@remix-run/node';
import { json } from '@remix-run/node';
import { useLoaderData } from '@remix-run/react';
import { Badge, BlockStack, Card, DataTable, Page, Text } from '@shopify/polaris';

import { backendFetch, sessionTokenFromRequest } from '~/utils/api.server';
import type { Audit } from '~/utils/types';

export async function loader({ request, params }: LoaderFunctionArgs) {
  const token = sessionTokenFromRequest(request);
  const { audit } = await backendFetch<{ audit: Audit }>(`/audits/${params.id}`, token);

  return json({ audit });
}

const SEVERITY_TONE = { high: 'critical', medium: 'warning', low: 'info' } as const;

export default function AuditDetail() {
  const { audit } = useLoaderData<typeof loader>();

  const rows = (audit.issues ?? []).map((issue) => [
    <Badge key={`sev-${issue.id}`} tone={SEVERITY_TONE[issue.severity]}>
      {issue.severity}
    </Badge>,
    issue.category,
    issue.title,
    issue.risk_tier,
    issue.fix_available ? 'Auto-fixable' : 'Recommendation only',
  ]);

  return (
    <Page title={`Audit #${audit.id}`} subtitle={audit.url ?? undefined}>
      <BlockStack gap="400">
        <Card>
          <Text as="h2" variant="headingLg">
            Score: {audit.score ?? '—'}
          </Text>
        </Card>
        <Card>
          <DataTable
            columnContentTypes={['text', 'text', 'text', 'text', 'text']}
            headings={['Severity', 'Category', 'Issue', 'Risk tier', 'Fix']}
            rows={rows}
          />
        </Card>
      </BlockStack>
    </Page>
  );
}
