import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Badge, BlockStack, Card, InlineStack, SkeletonBodyText, Text } from '@shopify/polaris';
import { api } from '../api';

const PAGE_TYPE_LABEL = {
    home: 'Homepage', product: 'Product page', collection: 'Collection page',
    cart: 'Cart', search: 'Search', blog: 'Blog article', custom: 'Custom URL',
};

/**
 * The headline reason to open the app even on a week nothing felt broken:
 * a clear "you lost/gained N points this week, here's why" instead of a
 * score a merchant has to interpret themselves. Same diff data
 * MonitoringRecorder already sends to Slack on a day-to-day regression,
 * rolled up over 7 days and put somewhere a merchant will actually see it.
 */
export default function WeeklyRollup() {
    const navigate = useNavigate();
    const [rollup, setRollup] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        api.get('/monitoring/weekly-rollup').then(setRollup).catch(() => setRollup(null)).finally(() => setLoading(false));
    }, []);

    if (loading) {
        return <Card><SkeletonBodyText lines={2} /></Card>;
    }

    if (!rollup?.has_data) {
        return null;
    }

    const { baseline_score: baselineScore, current_score: currentScore, delta, diff } = rollup;
    const improved = delta > 0;
    const worsened = delta < 0;

    const newScripts = diff?.new_third_party_scripts ?? [];
    const newIssues = diff?.new_issues ?? [];
    const worstPage = (diff?.page_type_deltas ?? [])
        .filter((p) => p.delta !== null && p.delta < 0)
        .sort((a, b) => a.delta - b.delta)[0];

    return (
        <Card>
            <BlockStack gap="200">
                <InlineStack align="space-between" blockAlign="center" wrap>
                    <Text as="h2" variant="headingSm">
                        This week: {baselineScore} → {currentScore}
                    </Text>
                    {delta !== null && (
                        <Badge tone={improved ? 'success' : worsened ? 'critical' : undefined}>
                            {improved ? `+${delta} points` : worsened ? `${delta} points` : 'No change'}
                        </Badge>
                    )}
                </InlineStack>
                {worsened && (newScripts.length > 0 || newIssues.length > 0 || worstPage) && (
                    <BlockStack gap="100">
                        <Text as="p" tone="subdued">Possible contributors this week (not confirmed causes):</Text>
                        {newScripts.length > 0 && (
                            <Text as="p">- New script(s): {newScripts.map((s) => s.app_name).join(', ')}</Text>
                        )}
                        {newIssues.length > 0 && (
                            <Text as="p">- {newIssues.length} new issue(s): {newIssues.slice(0, 3).map((i) => i.title).join('; ')}</Text>
                        )}
                        {worstPage && (
                            <Text as="p">
                                - Biggest drop: {PAGE_TYPE_LABEL[worstPage.page_type] ?? worstPage.page_type}{' '}
                                ({worstPage.previous_score} → {worstPage.current_score})
                            </Text>
                        )}
                    </BlockStack>
                )}
                {!worsened && improved && (
                    <Text as="p" tone="subdued">No regressions this week - performance improved overall.</Text>
                )}
                <InlineStack>
                    <Text as="span" tone="magic">
                        <a onClick={() => navigate('/monitoring')} style={{ cursor: 'pointer', color: 'inherit', textDecoration: 'underline' }}>
                            View full monitoring
                        </a>
                    </Text>
                </InlineStack>
            </BlockStack>
        </Card>
    );
}
