import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Badge, BlockStack, Card, InlineStack, SkeletonBodyText, Text } from '@shopify/polaris';
import { api } from '../api';

const STATUS_CONFIG = {
    healthy: { emoji: '🟢', label: 'Healthy', tone: 'success' },
    attention: { emoji: '🟡', label: 'Attention', tone: 'warning' },
    critical: { emoji: '🔴', label: 'Critical', tone: 'critical' },
    unknown: { emoji: '⚪', label: 'Not yet monitored', tone: undefined },
};

/**
 * The reason to open the app on a day nothing was explicitly broken - a
 * merchant whose store is optimized still has a reason to check this,
 * because it's the one place that says whether anything has changed since.
 */
export default function PerformanceHealth() {
    const navigate = useNavigate();
    const [health, setHealth] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        api.get('/monitoring/health').then(setHealth).catch(() => setHealth(null)).finally(() => setLoading(false));
    }, []);

    if (loading) {
        return <Card><SkeletonBodyText lines={2} /></Card>;
    }

    if (!health) {
        return null;
    }

    const cfg = STATUS_CONFIG[health.status] ?? STATUS_CONFIG.unknown;

    return (
        <Card>
            <InlineStack align="space-between" blockAlign="center" wrap={false}>
                <InlineStack gap="300" blockAlign="center">
                    <Text as="span" variant="headingLg">{cfg.emoji}</Text>
                    <BlockStack gap="050">
                        <Text as="h2" variant="headingSm">Performance health: {cfg.label}</Text>
                        {health.run_at ? (
                            <Text as="p" tone="subdued">
                                Last checked {new Date(health.run_at).toLocaleDateString()}
                                {health.score !== null ? ` · Score ${health.score}` : ''}
                                {health.new_third_party_scripts > 0 ? ` · ${health.new_third_party_scripts} new script(s)` : ''}
                                {health.new_issues > 0 ? ` · ${health.new_issues} new issue(s)` : ''}
                            </Text>
                        ) : (
                            <Text as="p" tone="subdued">Monitoring hasn't run yet - it starts automatically after your first scan.</Text>
                        )}
                    </BlockStack>
                </InlineStack>
                <InlineStack gap="200" blockAlign="center">
                    {cfg.tone && <Badge tone={cfg.tone}>{cfg.label}</Badge>}
                    <Text as="span" tone="magic">
                        <a onClick={() => navigate('/monitoring')} style={{ cursor: 'pointer', color: 'inherit', textDecoration: 'underline' }}>
                            View monitoring
                        </a>
                    </Text>
                </InlineStack>
            </InlineStack>
        </Card>
    );
}
