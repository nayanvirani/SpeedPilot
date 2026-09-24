import React, { useEffect, useState } from 'react';
import { Badge, BlockStack, Card, InlineStack, SkeletonBodyText, Text } from '@shopify/polaris';
import { api } from '../api';

/**
 * Answers, in one place, the two questions that came up confused: "is this
 * actually applying anywhere, and to which theme?" and "why does it say
 * recommended/blocked instead of applied?" Shown wherever a merchant (or
 * whoever's explaining this app to them) would otherwise have no way to
 * tell "Shopify hasn't approved this app's theme access yet" apart from
 * "this is broken."
 */
export default function ThemeAccessStatus() {
    const [settings, setSettings] = useState(null);
    const [themes, setThemes] = useState(null);

    useEffect(() => {
        Promise.all([
            api.get('/settings').catch(() => null),
            api.get('/themes').catch(() => ({ themes: [] })),
        ]).then(([s, t]) => {
            setSettings(s);
            setThemes(t.themes ?? []);
        });
    }, []);

    if (settings === null) {
        return <Card><SkeletonBodyText lines={2} /></Card>;
    }

    const themeName = themes?.find((t) => t.id === settings.target_theme_id)?.name;
    const blocked = !!settings.theme_write_blocked_at;

    return (
        <Card>
            <BlockStack gap="200">
                <Text as="h3" variant="headingSm">Theme write status</Text>
                <InlineStack gap="600" wrap>
                    <BlockStack gap="050">
                        <Text as="span" tone="subdued">Target theme</Text>
                        {settings.target_theme_id ? (
                            <Text as="span" fontWeight="medium">
                                {themeName ?? settings.target_theme_id} ({settings.target_theme_mode === 'live' ? 'Live' : 'Preview'})
                            </Text>
                        ) : (
                            <Text as="span" fontWeight="medium">Not set - choose one in Settings</Text>
                        )}
                    </BlockStack>
                    <BlockStack gap="050">
                        <Text as="span" tone="subdued">Theme-editing access</Text>
                        <Badge tone={blocked ? 'warning' : 'success'}>
                            {blocked ? 'Not yet approved by Shopify' : 'Available'}
                        </Badge>
                    </BlockStack>
                </InlineStack>
                {blocked && (
                    <Text as="p" tone="subdued">
                        Shopify hasn't approved this app's theme-editing access yet - a one-time approval
                        Shopify grants (or doesn't) to the app itself, not a setting any merchant can turn
                        on from their store. Until that's approved, "Disabled (auto)", "Delayed (auto)",
                        and the automatic side of "Advanced delay" will all show this same message -
                        "Manual fix" still works in the meantime, since it never needs write access at all.
                    </Text>
                )}
            </BlockStack>
        </Card>
    );
}
