import React, { useCallback, useEffect, useState } from 'react';
import { Banner, BlockStack, Button, Card, InlineStack, Page, Select, Text, TextField } from '@shopify/polaris';
import { api } from '../api';

function StorefrontPasswordSettings() {
    const [hasPassword, setHasPassword] = useState(null);
    const [value, setValue] = useState('');
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);

    useEffect(() => {
        api.get('/settings').then((res) => setHasPassword(res.has_storefront_password)).catch(() => setHasPassword(false));
    }, []);

    async function save() {
        setSaving(true);
        setSaved(false);
        try {
            const res = await api.put('/settings/storefront-password', { password: value });
            setHasPassword(res.has_storefront_password);
            setValue('');
            setSaved(true);
        } finally {
            setSaving(false);
        }
    }

    if (hasPassword === null) {
        return null;
    }

    return (
        <Card>
            <BlockStack gap="200">
                <Text as="h3" variant="headingSm"><span className="sp-heading">Storefront password</span></Text>
                <Text as="p" tone="subdued">
                    {hasPassword
                        ? "A password is saved - SpeedPilot unlocks your store automatically before every scan."
                        : "If your store has Shopify's storefront password enabled (every dev store does by default), scans can't reach real content without it."}
                </Text>
                <InlineStack gap="200" blockAlign="end">
                    <div style={{ flexGrow: 1, maxWidth: '280px' }}>
                        <TextField
                            label="Password"
                            labelHidden
                            type="password"
                            placeholder={hasPassword ? 'Update password' : 'Storefront password'}
                            value={value}
                            onChange={(v) => { setValue(v); setSaved(false); }}
                            autoComplete="off"
                        />
                    </div>
                    <Button loading={saving} disabled={!value} onClick={save}>Save</Button>
                    {saved && <Text as="span" tone="success">Saved</Text>}
                </InlineStack>
            </BlockStack>
        </Card>
    );
}

function TargetThemeSettings() {
    const [themes, setThemes] = useState(null);
    const [current, setCurrent] = useState({ id: null, mode: null, diverged: null });
    const [selectedThemeId, setSelectedThemeId] = useState('');
    const [saving, setSaving] = useState(null);

    const load = useCallback(async () => {
        const [settings, themeList] = await Promise.all([
            api.get('/settings'),
            api.get('/themes').catch(() => ({ themes: [] })),
        ]);
        setCurrent({
            id: settings.target_theme_id,
            mode: settings.target_theme_mode,
            diverged: settings.theme_diverged,
        });
        setThemes(themeList.themes);
        if (!selectedThemeId) {
            // The dropdown should reflect what's actually configured, not
            // just whatever Shopify happened to list first - fall back to
            // the live theme, then the first theme, only when nothing is
            // set yet.
            const initial = settings.target_theme_id
                ?? themeList.themes.find((t) => t.role === 'main')?.id
                ?? themeList.themes[0]?.id;
            if (initial) {
                setSelectedThemeId(initial);
            }
        }
    }, [selectedThemeId]);

    useEffect(() => { load(); }, []); // eslint-disable-line react-hooks/exhaustive-deps

    async function setTargetTheme() {
        setSaving(true);
        try {
            const res = await api.put('/settings/target-theme', { theme_id: selectedThemeId });
            setCurrent((c) => ({ ...c, id: res.target_theme_id, mode: res.target_theme_mode, diverged: null }));
        } finally {
            setSaving(false);
        }
    }

    if (themes === null) {
        return null;
    }

    const currentThemeName = themes.find((t) => t.id === current.id)?.name;
    const selectedIsLive = themes.find((t) => t.id === selectedThemeId)?.role === 'main';

    return (
        <Card>
            <BlockStack gap="300">
                <Text as="h3" variant="headingSm"><span className="sp-heading">Target theme</span></Text>
                <Text as="p" tone="subdued">
                    Choose which theme SpeedPilot applies fixes to. Nothing is auto-fixed or
                    changed on the App &amp; Script Impact page until you choose one here. Pick your
                    live theme for fixes to take effect immediately, or pick a theme you've already
                    duplicated yourself (Shopify admin &gt; Online Store &gt; Themes &gt; Duplicate)
                    to preview fixes safely first - SpeedPilot never creates or duplicates a theme
                    for you.
                </Text>

                {current.mode === 'live' && (
                    <Banner tone="warning">
                        Applying directly to <b>{currentThemeName ?? 'your live theme'}</b> - fixes take effect on
                        your storefront immediately.
                    </Banner>
                )}
                {current.mode === 'duplicate' && (
                    <Banner tone="success">
                        Applying to <b>{currentThemeName ?? 'your preview theme'}</b> - not your live theme, so
                        your storefront is unaffected until you review and publish it yourself from Shopify's
                        theme editor.
                    </Banner>
                )}
                {current.mode === 'duplicate' && current.diverged && (
                    <Banner tone="warning" title="Your live theme has changed since this preview was last refreshed">
                        Edits made directly to your live theme aren't reflected in this preview theme yet.
                        Publishing it now could revert those changes - run a new scan to refresh it first.
                    </Banner>
                )}
                {!current.mode && (
                    <Banner tone="info">No target theme set yet - fixes will stay recommendation-only until you pick one.</Banner>
                )}

                <InlineStack gap="200" blockAlign="end" wrap>
                    <div style={{ minWidth: '260px' }}>
                        <Select
                            label="Theme"
                            options={themes.map((t) => ({ label: `${t.name} (${t.role})`, value: t.id }))}
                            value={selectedThemeId}
                            onChange={setSelectedThemeId}
                        />
                    </div>
                    <Button loading={saving} onClick={setTargetTheme} disabled={!selectedThemeId} variant="primary">
                        {selectedIsLive ? 'Apply to this theme directly' : 'Use as preview theme'}
                    </Button>
                </InlineStack>
            </BlockStack>
        </Card>
    );
}

const FREQUENCY_OPTIONS = [
    { label: 'Daily', value: 'daily' },
    { label: 'Weekly', value: 'weekly' },
];
const DEVICE_OPTIONS = [
    { label: 'Mobile and desktop', value: 'both' },
    { label: 'Mobile only', value: 'mobile' },
    { label: 'Desktop only', value: 'desktop' },
];

function ScanPreferencesSettings() {
    const [prefs, setPrefs] = useState(null);
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);

    useEffect(() => {
        api.get('/settings').then((res) => setPrefs({
            scan_frequency: res.scan_frequency ?? 'daily',
            scan_devices: res.scan_devices ?? 'both',
        }));
    }, []);

    async function save(next) {
        setPrefs(next);
        setSaving(true);
        setSaved(false);
        try {
            await api.put('/settings/scan-preferences', next);
            setSaved(true);
        } finally {
            setSaving(false);
        }
    }

    if (!prefs) {
        return null;
    }

    return (
        <Card>
            <BlockStack gap="300">
                <Text as="h3" variant="headingSm"><span className="sp-heading">Scan preferences</span></Text>
                <Text as="p" tone="subdued">
                    Controls SpeedPilot's automatic monitoring re-scans and which device(s) every scan
                    covers. Clicking "Scan My Store" always runs immediately regardless of frequency.
                </Text>
                <InlineStack gap="300" wrap>
                    <div style={{ minWidth: '200px' }}>
                        <Select
                            label="Automatic scan frequency"
                            options={FREQUENCY_OPTIONS}
                            value={prefs.scan_frequency}
                            onChange={(v) => save({ ...prefs, scan_frequency: v })}
                        />
                    </div>
                    <div style={{ minWidth: '200px' }}>
                        <Select
                            label="Devices to scan"
                            options={DEVICE_OPTIONS}
                            value={prefs.scan_devices}
                            onChange={(v) => save({ ...prefs, scan_devices: v })}
                        />
                    </div>
                </InlineStack>
                {saving && <Text as="span" tone="subdued">Saving…</Text>}
                {!saving && saved && <Text as="span" tone="success">Saved</Text>}
            </BlockStack>
        </Card>
    );
}

function SlackNotificationSettings() {
    const [hasWebhook, setHasWebhook] = useState(null);
    const [value, setValue] = useState('');
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        api.get('/settings').then((res) => setHasWebhook(res.has_slack_webhook)).catch(() => setHasWebhook(false));
    }, []);

    async function save() {
        setSaving(true);
        setSaved(false);
        setError(null);
        try {
            const res = await api.put('/settings/slack-webhook', { webhook_url: value });
            setHasWebhook(res.has_slack_webhook);
            setValue('');
            setSaved(true);
        } catch (e) {
            setError(e.body?.error || 'Could not save that webhook URL.');
        } finally {
            setSaving(false);
        }
    }

    async function remove() {
        setSaving(true);
        try {
            const res = await api.put('/settings/slack-webhook', { webhook_url: null });
            setHasWebhook(res.has_slack_webhook);
        } finally {
            setSaving(false);
        }
    }

    if (hasWebhook === null) {
        return null;
    }

    return (
        <Card>
            <BlockStack gap="200">
                <Text as="h3" variant="headingSm"><span className="sp-heading">Slack notifications</span></Text>
                <Text as="p" tone="subdued">
                    Get a Slack message when SpeedPilot detects a performance regression (score drops
                    5+ points) or automatically applies fixes. Create an
                    incoming webhook in Slack (Apps &gt; Incoming Webhooks) and paste its URL here.
                </Text>
                {hasWebhook && (
                    <InlineStack gap="200" blockAlign="center">
                        <Text as="span" tone="success">A webhook is connected.</Text>
                        <Button size="micro" tone="critical" loading={saving} onClick={remove}>Disconnect</Button>
                    </InlineStack>
                )}
                <InlineStack gap="200" blockAlign="end">
                    <div style={{ flexGrow: 1, maxWidth: '380px' }}>
                        <TextField
                            label="Slack webhook URL"
                            labelHidden
                            placeholder="https://hooks.slack.com/services/..."
                            value={value}
                            onChange={(v) => { setValue(v); setSaved(false); setError(null); }}
                            autoComplete="off"
                            error={error}
                        />
                    </div>
                    <Button loading={saving} disabled={!value} onClick={save}>{hasWebhook ? 'Update' : 'Connect'}</Button>
                    {saved && <Text as="span" tone="success">Saved</Text>}
                </InlineStack>
            </BlockStack>
        </Card>
    );
}

export default function Settings() {
    return (
        <Page title="Settings">
            <BlockStack gap="400">
                <ScanPreferencesSettings />
                <TargetThemeSettings />
                <StorefrontPasswordSettings />
                <SlackNotificationSettings />
            </BlockStack>
        </Page>
    );
}
