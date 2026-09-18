import { useState } from 'react';
import type { LoaderFunctionArgs } from '@remix-run/node';
import { json } from '@remix-run/node';
import { useLoaderData, useRevalidator } from '@remix-run/react';
import { Badge, Button, Card, DataTable, Page } from '@shopify/polaris';

import { backendFetch, sessionTokenFromRequest } from '~/utils/api.server';
import { getSessionToken } from '~/utils/shopify.client';
import type { Optimization } from '~/utils/types';

export async function loader({ request }: LoaderFunctionArgs) {
  const token = sessionTokenFromRequest(request);
  const { optimizations } = await backendFetch<{ optimizations: Optimization[] }>(
    '/optimizations',
    token,
  );

  return json({ optimizations });
}

const STATUS_TONE = { applied: 'success', rolled_back: 'new', recommended: 'info' } as const;

export default function OptimizationsPage() {
  const { optimizations } = useLoaderData<typeof loader>();
  const revalidator = useRevalidator();
  const [pendingId, setPendingId] = useState<number | null>(null);

  async function rollback(id: number) {
    setPendingId(id);
    try {
      const token = await getSessionToken();
      await fetch(`/api-proxy/optimizations/${id}/rollback`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}` },
      });
      revalidator.revalidate();
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
        <DataTable
          columnContentTypes={['text', 'text', 'text', 'text', 'text']}
          headings={['Fix', 'Risk tier', 'Status', 'Applied at', 'Action']}
          rows={rows}
        />
      </Card>
    </Page>
  );
}
