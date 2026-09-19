import { useState } from 'react';
import { Badge, ButtonGroup, Button, Card, DataTable, Page, SkeletonBodyText, Text } from '@shopify/polaris';

import { apiPatch, useApiData } from '~/utils/useApiData';
import type { AppImpact } from '~/utils/types';

const IMPACT_TONE = { high: 'critical', medium: 'warning', low: 'success' } as const;

export default function AppImpactPage() {
  const { data, loading, refetch } = useApiData<{ app_impacts: AppImpact[] }>('/app-impacts');
  const appImpacts = data?.app_impacts ?? [];
  const [pendingId, setPendingId] = useState<number | null>(null);

  async function setStatus(id: number, status: string) {
    setPendingId(id);
    try {
      await apiPatch(`/app-impacts/${id}`, { status, persist_as_rule: true });
      await refetch();
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
        {loading ? (
          <SkeletonBodyText lines={4} />
        ) : appImpacts.length === 0 ? (
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
