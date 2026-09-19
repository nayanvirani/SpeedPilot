import { useState } from 'react';
import { Badge, BlockStack, Card, InlineStack, Page, SkeletonBodyText, Text } from '@shopify/polaris';

import { apiPost, useApiData } from '~/utils/useApiData';
import type { Audit } from '~/utils/types';

export default function Dashboard() {
  const { data, loading, refetch } = useApiData<{ audits: Audit[] }>('/audits');
  const [scanning, setScanning] = useState(false);
  const latestAudit = data?.audits?.[0] ?? null;

  async function scanNow() {
    setScanning(true);
    try {
      await apiPost('/audits');
      await refetch();
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
          {loading ? (
            <SkeletonBodyText lines={4} />
          ) : !latestAudit ? (
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
