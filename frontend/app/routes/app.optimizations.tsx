import { useState } from 'react';
import { Badge, Button, Card, DataTable, Page, SkeletonBodyText } from '@shopify/polaris';

import { apiPost, useApiData } from '~/utils/useApiData';
import type { Optimization } from '~/utils/types';

const STATUS_TONE = { applied: 'success', rolled_back: 'new', recommended: 'info' } as const;

export default function OptimizationsPage() {
  const { data, loading, refetch } = useApiData<{ optimizations: Optimization[] }>('/optimizations');
  const optimizations = data?.optimizations ?? [];
  const [pendingId, setPendingId] = useState<number | null>(null);

  async function rollback(id: number) {
    setPendingId(id);
    try {
      await apiPost(`/optimizations/${id}/rollback`);
      await refetch();
    } finally {
      setPendingId(null);
    }
  }

  const rows = optimizations.map((opt) => [
    opt.type,
    opt.risk_tier,
    <Badge key={`status-${opt.id}`} tone={STATUS_TONE[opt.status]}>
      {opt.status}
    </Badge>,
    opt.applied_at ?? '—',
    opt.status === 'applied' ? (
      <Button key={`rollback-${opt.id}`} size="micro" loading={pendingId === opt.id} onClick={() => rollback(opt.id)}>
        Rollback
      </Button>
    ) : (
      '—'
    ),
  ]);

  return (
    <Page title="Optimizations">
      <Card>
        {loading ? (
          <SkeletonBodyText lines={4} />
        ) : (
          <DataTable
            columnContentTypes={['text', 'text', 'text', 'text', 'text']}
            headings={['Fix', 'Risk tier', 'Status', 'Applied at', 'Action']}
            rows={rows}
          />
        )}
      </Card>
    </Page>
  );
}
