import type { LoaderFunctionArgs } from '@remix-run/node';
import { json } from '@remix-run/node';
import { useLoaderData } from '@remix-run/react';
import { Badge, Card, DataTable, Page, Text } from '@shopify/polaris';

import { backendFetch, sessionTokenFromRequest } from '~/utils/api.server';
import type { MonitoringRun } from '~/utils/types';

export async function loader({ request }: LoaderFunctionArgs) {
  const token = sessionTokenFromRequest(request);
  const { monitoring_runs } = await backendFetch<{ monitoring_runs: MonitoringRun[] }>(
    '/monitoring/trend',
    token,
  );

  return json({ runs: monitoring_runs });
}

export default function MonitoringPage() {
  const { runs } = useLoaderData<typeof loader>();

  const rows = runs.map((run) => [
    new Date(run.run_at).toLocaleDateString(),
    run.audit.score ?? '—',
    run.trend_delta !== null ? (
      <Badge key={run.id} tone={run.trend_delta >= 0 ? 'success' : 'critical'}>
        {run.trend_delta >= 0 ? `+${run.trend_delta}` : `${run.trend_delta}`}
      </Badge>
    ) : (
      '—'
    ),
  ]);

  return (
    <Page title="Monitoring">
      <Card>
        {runs.length === 0 ? (
          <Text as="p" tone="subdued">
            Monitoring history will appear here once daily scans start running (requires a
            Starter plan or above).
          </Text>
        ) : (
          <DataTable
            columnContentTypes={['text', 'numeric', 'text']}
            headings={['Date', 'Score', 'Change']}
            rows={rows}
          />
        )}
      </Card>
    </Page>
  );
}
