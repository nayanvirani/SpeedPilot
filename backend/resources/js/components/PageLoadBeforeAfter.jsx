import React, { useEffect, useState } from 'react';
import { Badge, BlockStack, Card, InlineStack, SkeletonBodyText, Text } from '@shopify/polaris';
import { api } from '../api';

function formatSeconds(value) {
    return value !== null && value !== undefined ? `${value.toFixed(1)}s` : '—';
}

function SecondaryStat({ label, before, after }) {
    return (
        <InlineStack gap="150" blockAlign="center">
            <Text as="span" tone="subdued">{label}:</Text>
            <Text as="span">{formatSeconds(before)}</Text>
            <Text as="span" tone="subdued">→</Text>
            <Text as="span" fontWeight="medium">{formatSeconds(after)}</Text>
        </InlineStack>
    );
}

/**
 * The concrete, plain-language proof point next to the abstract 0-100
 * score: "your homepage loaded in 8.2s, now it loads in 3.1s" - the actual
 * seconds a shopper waits, before SpeedPilot's very first scan against the
 * most recent one, not just a day-to-day trend line.
 */
export default function PageLoadBeforeAfter() {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        api.get('/monitoring/before-after').then(setData).catch(() => setData(null)).finally(() => setLoading(false));
    }, []);

    if (loading) {
        return <Card><SkeletonBodyText lines={2} /></Card>;
    }

    if (!data?.before) {
        return null;
    }

    const { before, after } = data;

    if (!after || before.lcp === null || after.lcp === null) {
        return (
            <Card>
                <BlockStack gap="150">
                    <Text as="h2" variant="headingSm">Page load time</Text>
                    <Text as="p" tone="subdued">
                        Run another scan after applying some fixes to see your actual load time improve, in
                        seconds - not just the 0-100 score.
                    </Text>
                </BlockStack>
            </Card>
        );
    }

    const deltaSeconds = before.lcp - after.lcp;
    const improved = deltaSeconds > 0.05;
    const worsened = deltaSeconds < -0.05;

    return (
        <Card>
            <BlockStack gap="300">
                <InlineStack align="space-between" blockAlign="center">
                    <Text as="h2" variant="headingSm">Page load time</Text>
                    {(improved || worsened) && (
                        <Badge tone={improved ? 'success' : 'critical'}>
                            {improved
                                ? `${Math.abs(deltaSeconds).toFixed(1)}s faster`
                                : `${Math.abs(deltaSeconds).toFixed(1)}s slower`}
                        </Badge>
                    )}
                </InlineStack>
                <InlineStack gap="400" blockAlign="baseline">
                    <BlockStack gap="050">
                        <Text as="span" tone="subdued">Before</Text>
                        <Text as="span" variant="heading2xl">{formatSeconds(before.lcp)}</Text>
                    </BlockStack>
                    <Text as="span" variant="headingLg" tone="subdued">→</Text>
                    <BlockStack gap="050">
                        <Text as="span" tone="subdued">Now</Text>
                        <Text as="span" variant="heading2xl" tone={improved ? 'success' : worsened ? 'critical' : undefined}>
                            {formatSeconds(after.lcp)}
                        </Text>
                    </BlockStack>
                </InlineStack>
                <Text as="p" tone="subdued">
                    Largest Contentful Paint - how long until a shopper actually sees your page's main
                    content, averaged across scanned pages. Since your first scan on{' '}
                    {new Date(before.created_at).toLocaleDateString()}.
                </Text>
                <InlineStack gap="400" wrap>
                    <SecondaryStat label="First paint" before={before.fcp} after={after.fcp} />
                    <SecondaryStat label="Speed Index" before={before.speed_index} after={after.speed_index} />
                </InlineStack>
            </BlockStack>
        </Card>
    );
}
