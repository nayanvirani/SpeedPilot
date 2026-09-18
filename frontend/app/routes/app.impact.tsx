import { useState } from 'react';
import type { LoaderFunctionArgs } from '@remix-run/node';
import { json } from '@remix-run/node';
import { useLoaderData, useRevalidator } from '@remix-run/react';
import { Badge, ButtonGroup, Button, Card, DataTable, Page, Text } from '@shopify/polaris';

import { backendFetch, sessionTokenFromRequest } from '~/utils/api.server';
import { getSessionToken } from '~/utils/shopify.client';
import type { AppImpact } from '~/utils/types';

export async function loader({ request }: LoaderFunctionArgs) {
  const token = sessionTokenFromRequest(request);
  const { app_impacts } = await backendFetch<{ app_impacts: AppImpact[] }>('/app-impacts', token);

  return json({ appImpacts: app_impacts });
}

const IMPACT_TONE = { high: 'critical', medium: 'warning', low: 'success' } as const;

export default function AppImpactPage() {
  const { appImpacts } = useLoaderData<typeof loader>();
  const revalidator = useRevalidator();
  const [pendingId, setPendingId] = useState<number | null>(null);

  async function setStatus(id: number, status: string) {
    setPendingId(id);
    try {
      const token = await getSessionToken();
      await fetch(`/api-proxy/app-impacts/${id}`, {
        method: 'PATCH',
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({ status, persist_as_rule: true }),
      });
      revalidator.revalidate();
    } finally {
      setPendingId(null);
    }
  }

  const highImpactCount = appImpacts.filter((a) => a.impact_level === 'high').length;

  const rows = appImpacts.map((impact) => [
    impact.app_name,
    impact.requests,
    `${Math.round(impact.size_bytes / 1024)} KB`,
    <Badge key={`impact-${impact.id}`} tone={IMPACT_TONE[impact.impact_level]}>
      {impact.impact_level}
    </Badge>,
    <ButtonGroup key={`actions-${impact.id}`}>
      {(['active', 'disabled', 'delayed', 'excluded'] as const)
        .filter((action) => action !== impact.status)
        .map((action) => (
          <Button
            key={action}
            size="micro"
            loading={pendingId === impact.id}
            onClick={() => setStatus(impact.id, action)}
          >
            {action}
          </Button>
        ))}
    </ButtonGroup>,
  ]);

  return (
    <Page
      title="App & Script Impact"
      subtitle={
        highImpactCount > 0
          ? `${highImpactCount} apps are costing you significant load time`
          : undefined
      }
    >
      <Card>
        {appImpacts.length === 0 ? (
          <Text as="p" tone="subdued">
            Run a scan first to see which installed apps and scripts are slowing down your store.
          </Text>
        ) : (
          <DataTable
            columnContentTypes={['text', 'numeric', 'text', 'text', 'text']}
            headings={['App / Script', 'Requests', 'Size', 'Impact', 'Action']}
            rows={rows}
          />
        )}
      </Card>
    </Page>
  );
}
