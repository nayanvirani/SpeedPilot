import { useState } from 'react';
import type { LoaderFunctionArgs } from '@remix-run/node';
import { json } from '@remix-run/node';
import { useLoaderData, useRevalidator } from '@remix-run/react';
import { Badge, BlockStack, Button, Card, InlineStack, Page, Text } from '@shopify/polaris';

import { backendFetch, sessionTokenFromRequest } from '~/utils/api.server';
import { getSessionToken } from '~/utils/shopify.client';
import type { Audit } from '~/utils/types';

export async function loader({ request }: LoaderFunctionArgs) {
  const token = sessionTokenFromRequest(request);
  const { audits } = await backendFetch<{ audits: Audit[] }>('/audits', token);

  return json({ latestAudit: audits[0] ?? null });
}

export default function Dashboard() {
  const { latestAudit } = useLoaderData<typeof loader>();
  const revalidator = useRevalidator();
  const [scanning, setScanning] = useState(false);

  async function scanNow() {
    setScanning(true);
    try {
      const token = await getSessionToken();
      await fetch('/api-proxy/audits', {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}` },
      });
      revalidator.revalidate();
    } finally {
      setScanning(false);
    }
  }

  return (
    <Page
      title="SpeedPilot"
      primaryAction={{ content: 'Scan My Store', loading: scanning, onAction: scanNow }}
    >
      <BlockStack gap="400">
        <Card>
          {!latestAudit ? (
            <BlockStack gap="200">
              <Text as="h2" variant="headingMd">
                No scans yet
              </Text>
              <Text as="p" tone="subdued">
                Run your first free audit to see your store&apos;s performance score,
                Core Web Vitals, and which apps are slowing you down.
              </Text>
            </BlockStack>
          ) : (
            <BlockStack gap="300">
              <InlineStack align="space-between">
                <Text as="h2" variant="headingLg">
                  Score: {latestAudit.score ?? '—'}
                </Text>
                <Badge tone={statusTone(latestAudit.status)}>{latestAudit.status}</Badge>
              </InlineStack>
              <InlineStack gap="600">
                <Metric label="LCP" value={latestAudit.lcp} suffix="s" />
                <Metric label="INP" value={latestAudit.inp} suffix="ms" />
                <Metric label="CLS" value={latestAudit.cls} suffix="" />
                <Metric label="FCP" value={latestAudit.fcp} suffix="s" />
                <Metric label="TTFB" value={latestAudit.ttfb} suffix="s" />
              </InlineStack>
              {latestAudit.issues && latestAudit.issues.length > 0 && (
                <Text as="p">
                  {latestAudit.issues.length} issues found,{' '}
                  {latestAudit.issues.filter((i) => i.fix_available).length} auto-fixable.
                </Text>
              )}
            </BlockStack>
          )}
        </Card>
      </BlockStack>
    </Page>
  );
}

function Metric({ label, value, suffix }: { label: string; value: number | null; suffix: string }) {
  return (
    <BlockStack gap="100">
      <Text as="span" tone="subdued">
        {label}
      </Text>
      <Text as="span" variant="headingMd">
        {value !== null ? `${value}${suffix}` : '—'}
      </Text>
    </BlockStack>
  );
}

function statusTone(status: Audit['status']): 'success' | 'attention' | 'critical' | undefined {
  return status === 'complete' ? 'success' : status === 'failed' ? 'critical' : 'attention';
}
