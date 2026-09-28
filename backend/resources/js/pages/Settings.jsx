import React, { useCallback, useEffect, useState } from 'react';
import { Banner, BlockStack, Button, Card, InlineStack, Page, Select, Text, TextField } from '@shopify/polaris';
import { api } from '../api';

function StorefrontPasswordSettings() {
    const [hasPassword, setHasPassword] = useState(null);
    const [locked, setLocked] = useState(false);
    const [value, setValue] = useState('');
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);

    const load = useCallback(() => {
        return api.get('/settings')
            .then((res) => { setHasPassword(res.has_storefront_password); setLocked(!!res.storefront_locked); })
            .catch(() => setHasPassword(false));
    }, []);

    useEffect(() => { load(); }, [load]);

    async function save() {
        setSaving(true);
        setSaved(false);
        try {
            const res = await api.put('/settings/storefront-password', { password: value });
            setHasPassword(res.has_storefront_password);
            setValue('');
            setSaved(true);
            await load(); // re-checks storefront_locked now that a password is saved
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
                {locked && (
                    <Banner tone="critical">
                        Your storefront is currently password-protected and no password is saved -
                        scans can't reach real content until you add it below.
                    </Banner>
                )}
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

const TRIGGER_OPTIONS = [
    { label: 'Scroll, click, or key press (recommended)', value: 'interaction' },
    { label: 'Page fully loaded (window load)', value: 'window_load' },
    { label: 'HTML parsed (document load)', value: 'document_load' },
    { label: 'Timer only, no early trigger', value: 'timeout_only' },
];
const DELAY_OPTIONS = [3000, 5000, 8000, 10000, 15000, 20000].map((ms) => ({
    label: `${ms / 1000} seconds`,
    value: String(ms),
}));

function AdvancedDelayTimingSettings() {
    const [prefs, setPrefs] = useState(null);
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);

    useEffect(() => {
        api.get('/settings').then((res) => setPrefs({
            interceptor_delay_ms: res.interceptor_delay_ms ?? 5000,
            interceptor_trigger: res.interceptor_trigger ?? 'interaction',
        }));
    }, []);

    async function save(next) {
        setPrefs(next);
        setSaving(true);
        setSaved(false);
        try {
            await api.put('/settings/interceptor-timing', next);
            setSaved(true);
        } finally {
            setSaving(false);
        }
    }

    if (!prefs) {
        return null;
    }

    const isPageEventTrigger = prefs.interceptor_trigger === 'window_load' || prefs.interceptor_trigger === 'document_load';

    return (
        <Card>
            <BlockStack gap="300">
                <Text as="h3" variant="headingSm"><span className="sp-heading">Advanced delay timing</span></Text>
                <Text as="p" tone="subdued">
                    Controls when "Advanced delay (experimental)" releases a script it's holding back, on
                    the App &amp; Script Impact page. The timer below always applies as a fallback, even if
                    you pick an early trigger that never fires for some reason.
                </Text>
                {isPageEventTrigger && (
                    <Text as="p" tone="subdued">
                        With "{prefs.interceptor_trigger === 'window_load' ? 'Page fully loaded' : 'HTML parsed'}"
                        selected, the timer below is only a last-resort safety net (minimum 20 seconds, even if
                        set lower) - it won't release early just because the timer is short. This avoids the
                        timer racing and beating the real event on a page that takes longer to load than
                        expected (confirmed live: a background video pushed page-load past 5 seconds, so a
                        5-second timer released before the page had actually finished loading).
                    </Text>
                )}
                <InlineStack gap="300" wrap>
                    <div style={{ minWidth: '260px' }}>
                        <Select
                            label="Release early on"
                            options={TRIGGER_OPTIONS}
                            value={prefs.interceptor_trigger}
                            onChange={(v) => save({ ...prefs, interceptor_trigger: v })}
                        />
                    </div>
                    <div style={{ minWidth: '200px' }}>
                        <Select
                            label={isPageEventTrigger ? 'Safety-net timer (minimum 20s)' : 'Release after (timer)'}
                            options={DELAY_OPTIONS}
                            value={String(prefs.interceptor_delay_ms)}
                            onChange={(v) => save({ ...prefs, interceptor_delay_ms: Number(v) })}
                        />
                    </div>
                </InlineStack>
                {saving && <Text as="span" tone="subdued">Saving…</Text>}
                {!saving && saved && <Text as="span" tone="success">Saved</Text>}
            </BlockStack>
        </Card>
    );
}

function RevenueInputsSettings() {
    const [values, setValues] = useState(null);
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);

    useEffect(() => {
        api.get('/settings').then((res) => setValues({
            avg_order_value: res.avg_order_value !== null && res.avg_order_value !== undefined ? String(res.avg_order_value) : '',
            monthly_orders: res.monthly_orders !== null && res.monthly_orders !== undefined ? String(res.monthly_orders) : '',
        }));
    }, []);

    async function save() {
        setSaving(true);
        setSaved(false);
        try {
            const res = await api.put('/settings/revenue-inputs', {
                avg_order_value: values.avg_order_value === '' ? null : Number(values.avg_order_value),
                monthly_orders: values.monthly_orders === '' ? null : Number(values.monthly_orders),
            });
            setValues({
                avg_order_value: res.avg_order_value !== null ? String(res.avg_order_value) : '',
                monthly_orders: res.monthly_orders !== null ? String(res.monthly_orders) : '',
            });
            setSaved(true);
        } finally {
            setSaving(false);
        }
    }

    if (!values) {
        return null;
    }

    return (
        <Card>
            <BlockStack gap="200">
                <Text as="h3" variant="headingSm"><span className="sp-heading">Revenue inputs</span></Text>
                <Text as="p" tone="subdued">
                    Optional - adds a personalized dollar estimate to the Dashboard's page-load
                    improvement card, on top of the percentage estimate it always shows. Leave blank to
                    only see the percentage.
                </Text>
                <InlineStack gap="300" wrap>
                    <div style={{ minWidth: '200px' }}>
                        <TextField
                            label="Average order value ($)"
                            type="number"
                            min={0}
                            value={values.avg_order_value}
                            onChange={(v) => { setValues({ ...values, avg_order_value: v }); setSaved(false); }}
                            autoComplete="off"
                        />
                    </div>
                    <div style={{ minWidth: '200px' }}>
                        <TextField
                            label="Average monthly orders"
                            type="number"
                            min={0}
                            value={values.monthly_orders}
                            onChange={(v) => { setValues({ ...values, monthly_orders: v }); setSaved(false); }}
                            autoComplete="off"
                        />
                    </div>
                </InlineStack>
                <InlineStack gap="200" blockAlign="center">
                    <Button loading={saving} onClick={save}>Save</Button>
                    {saved && <Text as="span" tone="success">Saved</Text>}
                </InlineStack>
            </BlockStack>
        </Card>
    );
}

function SpeedBudgetSettings() {
    const [value, setValue] = useState(null);
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);

    useEffect(() => {
        api.get('/settings').then((res) => setValue(
            res.speed_budget_lcp_seconds !== null && res.speed_budget_lcp_seconds !== undefined
                ? String(res.speed_budget_lcp_seconds)
                : '',
        ));
    }, []);

    async function save() {
        setSaving(true);
        setSaved(false);
        try {
            const res = await api.put('/settings/speed-budget', {
                speed_budget_lcp_seconds: value === '' ? null : Number(value),
            });
            setValue(res.speed_budget_lcp_seconds !== null ? String(res.speed_budget_lcp_seconds) : '');
            setSaved(true);
        } finally {
            setSaving(false);
        }
    }

    if (value === null) {
        return null;
    }

    return (
        <Card>
            <BlockStack gap="200">
                <Text as="h3" variant="headingSm"><span className="sp-heading">Speed budget</span></Text>
                <Text as="p" tone="subdued">
                    Get a Slack alert the moment a scan's LCP crosses this line, instead of only finding
                    out from a score drop after the fact. Leave blank to disable.
                </Text>
                <InlineStack gap="200" blockAlign="end">
                    <div style={{ minWidth: '200px' }}>
                        <TextField
                            label="Alert if LCP exceeds (seconds)"
                            type="number"
                            min={0.1}
                            step={0.1}
                            value={value}
                            onChange={(v) => { setValue(v); setSaved(false); }}
                            autoComplete="off"
                        />
                    </div>
                    <Button loading={saving} onClick={save}>Save</Button>
                    {saved && <Text as="span" tone="success">Saved</Text>}
                </InlineStack>
            </BlockStack>
        </Card>
    );
}

function InstantNavigationSettings() {
    const [hasTag, setHasTag] = useState(null);
    const [installing, setInstalling] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        api.get('/settings').then((res) => setHasTag(!!res.has_prefetch_tag)).catch(() => setHasTag(false));
    }, []);

    async function install() {
        setInstalling(true);
        setError(null);
        try {
            const res = await api.post('/settings/prefetch-tag/install', {});
            if (res.applied) {
                setHasTag(true);
            } else {
                setError(res.message || 'Could not install this right now.');
            }
        } catch (e) {
            setError(e.body?.error || 'Could not install this right now.');
        } finally {
            setInstalling(false);
        }
    }

    if (hasTag === null) {
        return null;
    }

    return (
        <Card>
            <BlockStack gap="200">
                <Text as="h3" variant="headingSm"><span className="sp-heading">Instant navigation</span></Text>
                <Text as="p" tone="subdued">
                    Prefetches a page's content when a shopper hovers a link (or scrolls it into view on
                    mobile), so the next page feels instant by the time they click. Never touches cart,
                    checkout, or account links. Independent of Advanced Delay - turning this on doesn't
                    affect that setting.
                </Text>
                {hasTag ? (
                    <Text as="span" tone="success">Installed and active on your theme.</Text>
                ) : (
                    <InlineStack gap="200" blockAlign="center">
                        <Button variant="primary" loading={installing} onClick={install}>Enable instant navigation</Button>
                        {error && <Text as="span" tone="critical">{error}</Text>}
                    </InlineStack>
                )}
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
                <AdvancedDelayTimingSettings />
                <StorefrontPasswordSettings />
                <RevenueInputsSettings />
                <SpeedBudgetSettings />
                <InstantNavigationSettings />
                <SlackNotificationSettings />
            </BlockStack>
        </Page>
    );
}
