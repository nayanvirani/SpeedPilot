import React, { useCallback, useEffect, useState } from 'react';
import { Badge, Banner, BlockStack, Button, ButtonGroup, Card, InlineStack, Page, Select, SkeletonBodyText, Text, TextField, Toast } from '@shopify/polaris';
import { api } from '../api';
import FixCodeViewer from '../components/FixCodeViewer';
import ThemeAccessStatus from '../components/ThemeAccessStatus';
import { usePlan } from '../PlanContext';

const PAGE_TYPE_LABEL = {
    home: 'Homepage', product: 'Product pages', collection: 'Collection pages',
    cart: 'Cart', search: 'Search', blog: 'Blog articles', custom: 'Custom pages', default: 'All pages (default)',
};
const TRIGGER_LABEL = {
    never: 'Off on this page', immediate: 'Load immediately', interaction: 'After interaction (scroll/click)',
    timeout: 'After N seconds', scroll: 'After scroll',
};

const IMPACT_TONE = { high: 'critical', medium: 'warning', low: 'success' };
const STATUS_TONE = { active: 'success', disabled: 'critical', delayed: 'warning', excluded: 'new' };
const STATUS_LABEL = { active: 'Active', disabled: 'Disabled', delayed: 'Delayed', excluded: 'Excluded' };

// Three of these ('delayed', 'delayed_interceptor', 'stopped_content') all
// resolve to status=delayed on the server, distinguished by delay_method -
// 'theme_edit' (guaranteed, but only works when the script is found in
// theme files), 'content_replace' (guaranteed too, once SpeedPilot has
// actually observed the script as literal text in content_for_header - see
// confirm copy below), and 'interceptor' (best-effort client-side delay for
// everything else, weakest of the three).
const ACTION_META = {
    active: { label: 'Active', status: 'active' },
    disabled: { label: 'Disabled (auto)', status: 'disabled' },
    delayed: { label: 'Delayed (auto)', status: 'delayed' },
    stopped_content: { label: 'Stop (verified)', status: 'delayed', method: 'content_replace' },
    delayed_interceptor: { label: 'Advanced delay (experimental)', status: 'delayed', method: 'interceptor' },
    excluded: { label: 'Excluded', status: 'excluded' },
};

const ACTION_CONFIRM = {
    disabled: (name) => `Remove ${name}'s script from your theme? This will stop it from working on your storefront until you re-enable it.`,
    delayed: (name) => `Delay ${name}'s script until the shopper first scrolls, clicks, or after 5 seconds? Some of its functionality (like a chat widget appearing instantly) may be affected.`,
    stopped_content: (name) => `Stop ${name}'s script until the shopper first scrolls, clicks, or after 5 seconds? SpeedPilot verified this scan that ${name}'s script appears as real code in your storefront's page, so this edits your theme to neutralize it server-side, then reloads it on interaction - stronger than "Advanced delay," but still stops working if you ever uninstall SpeedPilot (the release step needs it).`,
    delayed_interceptor: (name) => `Try advanced delay for ${name}? This works by delaying resources ${name}'s own script loads dynamically after it starts - it can't guarantee delaying ${name}'s very first script tag, since that's loaded directly by Shopify and no app (including this one) can intercept it. Best-effort, not a guarantee like "Delayed (auto)." Some scripts (Shopify's own sandboxed marketing pixels) can't be delayed by any method, including this one. If ${name} also renders something visible on your storefront (a reviews widget, a chat button, a carousel), delaying it can make that specific widget show an error or fail to load - confirmed live with a reviews carousel that timed out waiting for its own script. Worth testing before leaving it on long-term. Requires "Advanced delay setup" above (Auto-fix or Manual fix) - shared by every app, not set up per app.`,
};

function currentActionKey(impact) {
    if ((impact.status ?? 'active') !== 'delayed') {
        return impact.status ?? 'active';
    }

    if (impact.delay_method === 'interceptor') return 'delayed_interceptor';
    if (impact.delay_method === 'content_replace') return 'stopped_content';

    return 'delayed';
}

/**
 * Every app with "Advanced delay (experimental)" enabled shares ONE watcher
 * script and ONE combined list of URLs it watches for - enabling it on a
 * 2nd, 3rd, 5th app doesn't create separate delays, it just adds that app's
 * URL to the same shared list automatically. The tag itself is identical
 * regardless of which (or how many) apps use it, so it's shown here exactly
 * once, globally - not repeated on every row, which would wrongly imply a
 * merchant needs to paste something different (or paste it again) per app.
 */
function AdvancedDelaySetup({ appImpacts }) {
    const delayed = appImpacts.filter((a) => a.status === 'delayed' && a.delay_method === 'interceptor');
    const [installing, setInstalling] = useState(false);
    const [installResult, setInstallResult] = useState(null);

    async function autoInstall() {
        setInstalling(true);
        setInstallResult(null);
        try {
            const res = await api.post('/advanced-delay/install');
            setInstallResult(res);
        } catch (e) {
            setInstallResult({ applied: false, message: e.body?.error || 'Could not install the tag right now.' });
        } finally {
            setInstalling(false);
        }
    }

    return (
        <Card>
            <BlockStack gap="200">
                <Text as="h2" variant="headingSm">Advanced delay setup</Text>
                <Text as="p" tone="subdued">
                    {delayed.length > 0
                        ? `Active for ${delayed.length} app${delayed.length === 1 ? '' : 's'}: ${delayed.map((a) => a.app_name).join(', ')}. `
                        : ''}
                    One tag, shared by every app you turn "Advanced delay" on for below - toggling it per app
                    never needs the theme touched again once it's added.
                </Text>
                <InlineStack gap="200">
                    <Button variant="primary" loading={installing} onClick={autoInstall}>
                        Auto-fix - add to my theme
                    </Button>
                    <FixCodeViewer fetchPath="/advanced-delay/fix-code" label="Manual fix - view code" />
                </InlineStack>
                {installResult && (
                    <Text as="span" tone={installResult.applied ? 'success' : 'critical'}>
                        {installResult.applied
                            ? 'Added to your theme - back it up or roll it back any time from the Optimizations page.'
                            : installResult.message}
                    </Text>
                )}
            </BlockStack>
        </Card>
    );
}

/**
 * Answers "what would my score be without this app" with a real second
 * scan (the app's URLs blocked via Lighthouse's own native option, not a
 * theme write) instead of a guess - never touches the live theme, so this
 * is safe to run before deciding whether to actually disable anything.
 */
function ProjectRemovalButton({ impact }) {
    const [loading, setLoading] = useState(false);
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);

    async function run() {
        setLoading(true);
        setError(null);
        try {
            const res = await api.post(`/app-impacts/${impact.id}/project-removal`, {});
            setResult(res);
        } catch (e) {
            setError(e.body?.error || 'Could not run this projection right now.');
        } finally {
            setLoading(false);
        }
    }

    if (result) {
        const improved = result.delta > 0;

        return (
            <Text as="span" tone={improved ? 'success' : 'subdued'}>
                Homepage score: {result.current_score} → without {impact.app_name}: {result.projected_score}
                {result.delta !== null && ` (${improved ? '+' : ''}${result.delta})`}
            </Text>
        );
    }

    return (
        <BlockStack gap="100">
            <Button size="micro" loading={loading} onClick={run}>
                {loading ? 'Running two scans (~1 min)…' : 'See projected impact'}
            </Button>
            {error && <Text as="span" tone="critical">{error}</Text>}
        </BlockStack>
    );
}

/**
 * Smart Script Manager for one app: "Chat widget on product pages -> off,
 * collection -> after scroll" - additive to the blunt global Disable/Delay
 * buttons above. Rules for this app_name are resolved server-side against
 * whichever page a shopper is actually on (InterceptorController::serve()),
 * not applied here - this panel only edits the rule rows.
 */
function ScriptPageRulesPanel({ appName, manager, onChange }) {
    const [pageType, setPageType] = useState('default');
    const [trigger, setTrigger] = useState('interaction');
    const [delaySeconds, setDelaySeconds] = useState('5');
    const [saving, setSaving] = useState(false);

    const rules = (manager?.rules ?? []).filter((r) => r.app_name === appName);
    const pageTypeOptions = (manager?.page_types ?? []).concat(manager?.default_page_type ? [manager.default_page_type] : [])
        .map((pt) => ({ label: PAGE_TYPE_LABEL[pt] ?? pt, value: pt }));
    const triggerOptions = (manager?.triggers ?? []).map((t) => ({ label: TRIGGER_LABEL[t] ?? t, value: t }));

    async function addRule() {
        setSaving(true);
        try {
            await api.put('/script-manager', {
                app_name: appName,
                page_type: pageType,
                trigger,
                delay_seconds: ['timeout', 'scroll'].includes(trigger) ? Number(delaySeconds) || 5 : null,
            });
            await onChange();
        } finally {
            setSaving(false);
        }
    }

    async function removeRule(id) {
        setSaving(true);
        try {
            await api.delete(`/script-manager/${id}`);
            await onChange();
        } finally {
            setSaving(false);
        }
    }

    return (
        <BlockStack gap="200">
            {rules.length > 0 && (
                <BlockStack gap="100">
                    {rules.map((r) => (
                        <InlineStack key={r.id} gap="200" blockAlign="center">
                            <Text as="span" fontWeight="medium">{PAGE_TYPE_LABEL[r.page_type] ?? r.page_type}:</Text>
                            <Text as="span" tone="subdued">
                                {TRIGGER_LABEL[r.trigger] ?? r.trigger}{r.delay_seconds ? ` (${r.delay_seconds}s)` : ''}
                            </Text>
                            <Button size="micro" variant="plain" tone="critical" loading={saving} onClick={() => removeRule(r.id)}>Remove</Button>
                        </InlineStack>
                    ))}
                </BlockStack>
            )}
            <InlineStack gap="200" blockAlign="end" wrap>
                <div style={{ minWidth: '170px' }}>
                    <Select label="Page" options={pageTypeOptions} value={pageType} onChange={setPageType} />
                </div>
                <div style={{ minWidth: '210px' }}>
                    <Select label="Behavior" options={triggerOptions} value={trigger} onChange={setTrigger} />
                </div>
                {['timeout', 'scroll'].includes(trigger) && (
                    <div style={{ width: '90px' }}>
                        <TextField label="Seconds" type="number" value={delaySeconds} onChange={setDelaySeconds} autoComplete="off" />
                    </div>
                )}
                <Button size="slim" loading={saving} onClick={addRule}>Save rule</Button>
            </InlineStack>
        </BlockStack>
    );
}

function ImpactRow({ impact, pending, onSetStatus, scriptManager, onScriptManagerChange }) {
    const { isFree } = usePlan();
    const [rulesOpen, setRulesOpen] = useState(false);
    // Shopify's own platform scripts (Shop Pay, checkout, core analytics)
    // are injected by Shopify itself, never present as literal text in the
    // theme's own files - no app, including this one, can disable, delay,
    // or show "the code" for something that isn't in the theme to begin
    // with. Excluding it from the list is still offered.
    // 'active'/'excluded' are dismiss/undo, allowed on Free the same as the
    // backend (AppImpactController::updateStatus) - every action that
    // actually touches the theme requires a paid plan.
    const actions = impact.is_platform || isFree
        ? ['active', 'excluded']
        : [
            'active', 'disabled', 'delayed', 'excluded',
            ...(impact.content_for_header_match ? ['stopped_content'] : []),
            'delayed_interceptor',
        ];
    const current = currentActionKey(impact);
    const badgeLabel = impact.status === 'delayed' && impact.delay_method === 'interceptor'
        ? 'Delayed (experimental)'
        : impact.status === 'delayed' && impact.delay_method === 'content_replace'
            ? 'Stopped (verified)'
            : STATUS_LABEL[impact.status ?? 'active'];

    return (
        <BlockStack gap="200">
            <InlineStack align="space-between" blockAlign="start" wrap>
                <BlockStack gap="050">
                    <InlineStack gap="150" blockAlign="center">
                        <Text as="span" fontWeight="semibold">{impact.app_name}</Text>
                        {impact.is_platform && <Badge tone="info">Shopify platform</Badge>}
                    </InlineStack>
                    <InlineStack gap="300">
                        <Text as="span" tone="subdued">{impact.requests} requests</Text>
                        <Text as="span" tone="subdued">{Math.round(impact.size_bytes / 1024)} KB</Text>
                        <Badge tone={IMPACT_TONE[impact.impact_level]}>{impact.impact_level}</Badge>
                        <Badge tone={STATUS_TONE[impact.status] ?? 'success'}>{badgeLabel}</Badge>
                    </InlineStack>
                    {impact.is_platform && (
                        <Text as="span" tone="subdued">
                            Loaded directly by Shopify, not an installed app - can't be disabled, delayed, or edited by any app.
                        </Text>
                    )}
                </BlockStack>
                <ButtonGroup>
                    {actions
                        .filter((action) => action !== current)
                        .map((action) => (
                            <Button key={action} size="micro" loading={pending} onClick={() => onSetStatus(impact, action)}>
                                {ACTION_META[action].label}
                            </Button>
                        ))}
                </ButtonGroup>
            </InlineStack>
            {!impact.is_platform && !isFree && (impact.status ?? 'active') === 'active' && (
                <InlineStack gap="200" blockAlign="center">
                    <FixCodeViewer
                        key={`disabled-${impact.id}`}
                        fetchPath={`/app-impacts/${impact.id}/fix-code?action=disabled`}
                        label="Manual fix - view code to disable"
                    />
                    <FixCodeViewer
                        key={`delayed-${impact.id}`}
                        fetchPath={`/app-impacts/${impact.id}/fix-code?action=delayed`}
                        label="Manual fix - view code to delay"
                    />
                    <ProjectRemovalButton impact={impact} />
                    <Button size="micro" variant="plain" onClick={() => setRulesOpen((v) => !v)}>
                        {rulesOpen ? 'Hide page rules' : 'Manage page rules'}
                    </Button>
                </InlineStack>
            )}
            {!impact.is_platform && !isFree && rulesOpen && (
                <ScriptPageRulesPanel appName={impact.app_name} manager={scriptManager} onChange={onScriptManagerChange} />
            )}
        </BlockStack>
    );
}

/**
 * Purely informational - overlap_category/overlap_with come from the
 * backend's curated category list (reviews, chat, popups, etc.), never
 * analytics/tracking apps, since running several of those simultaneously is
 * normal, not redundant. No action attached; this just gives the merchant
 * something to consider, since removing either app is their call.
 */
function OverlapBanner({ appImpacts }) {
    const seen = new Set();
    const groups = [];

    for (const impact of appImpacts) {
        if (!impact.overlap_category || seen.has(impact.overlap_category)) {
            continue;
        }

        seen.add(impact.overlap_category);
        groups.push({
            category: impact.overlap_category,
            names: [impact.app_name, ...impact.overlap_with],
        });
    }

    if (groups.length === 0) {
        return null;
    }

    return (
        <Banner tone="info" title="Possible overlap between installed apps">
            <BlockStack gap="150">
                {groups.map((g) => (
                    <Text as="p" key={g.category}>
                        You have {g.names.length} apps that all handle {g.category.replace('/', ' / ')}:{' '}
                        <b>{g.names.join(', ')}</b> - consider whether you need all of them.
                    </Text>
                ))}
            </BlockStack>
        </Banner>
    );
}

export default function AppImpact() {
    const { isFree } = usePlan();
    const [appImpacts, setAppImpacts] = useState([]);
    const [loading, setLoading] = useState(true);
    const [pendingId, setPendingId] = useState(null);
    const [notice, setNotice] = useState(null);
    const [scriptManager, setScriptManager] = useState(null);

    const load = useCallback(() => {
        setLoading(true);
        return api.get('/app-impacts')
            .then((res) => setAppImpacts(res.app_impacts))
            .finally(() => setLoading(false));
    }, []);

    const loadScriptManager = useCallback(() => (
        api.get('/script-manager').then(setScriptManager)
    ), []);

    useEffect(() => { load(); }, [load]);
    useEffect(() => { loadScriptManager(); }, [loadScriptManager]);

    async function setStatus(impact, actionKey) {
        if (ACTION_CONFIRM[actionKey] && !window.confirm(ACTION_CONFIRM[actionKey](impact.app_name))) {
            return;
        }

        const { status, method } = ACTION_META[actionKey];

        setPendingId(impact.id);
        setNotice(null);
        try {
            const res = await api.patch(`/app-impacts/${impact.id}`, { status, method });
            if (!res.applied) {
                setNotice({ tone: 'warning', message: res.message });
            } else {
                setNotice({ tone: 'success', message: `${impact.app_name} is now ${ACTION_META[actionKey].label.replace(' (auto)', '').toLowerCase()}.` });
            }
            await load();
        } finally {
            setPendingId(null);
        }
    }

    const highImpactCount = appImpacts.filter((a) => a.impact_level === 'high').length;

    return (
        <Page
            title="App & Script Impact"
            subtitle={highImpactCount > 0 ? `${highImpactCount} apps are costing you significant load time` : undefined}
        >
            {notice && (
                // A Toast, not just the Banner below - this list can run to
                // 15+ rows, and a banner pinned to the top of the page is
                // easy to miss entirely if the action that triggered it was
                // on a row further down and nothing visibly changes there.
                // Toast floats above the page regardless of scroll position.
                <Toast
                    content={notice.message}
                    error={notice.tone === 'warning' || notice.tone === 'critical'}
                    onDismiss={() => setNotice(null)}
                    duration={notice.tone === 'success' ? 3000 : 6000}
                />
            )}
            <BlockStack gap="400">
                {notice && (
                    <Banner tone={notice.tone} onDismiss={() => setNotice(null)}>
                        <Text as="p">{notice.message}</Text>
                    </Banner>
                )}
                {!isFree && <ThemeAccessStatus />}
                <OverlapBanner appImpacts={appImpacts} />
                {!isFree && <AdvancedDelaySetup appImpacts={appImpacts} />}
                <Card>
                    {loading ? (
                        <SkeletonBodyText lines={4} />
                    ) : appImpacts.length === 0 ? (
                        <Text as="p" tone="subdued">
                            Run a scan first to see which installed apps and scripts are slowing down your store.
                        </Text>
                    ) : (
                        <BlockStack gap="400">
                            <Text as="p" tone="subdued">
                                <b>Disabled (auto)</b> and <b>Delayed (auto)</b> have SpeedPilot edit your theme directly -
                                only works when SpeedPilot can find the script in your theme's files, and once
                                Shopify approves this app's theme-editing access. <b>Stop (verified)</b> is for scripts
                                injected by another app (no theme file to edit) that SpeedPilot has actually confirmed,
                                this scan, appear as real code in your storefront's rendered page - it edits your theme
                                to neutralize that exact code server-side, then reloads it on interaction, and only
                                appears as an option once that's been confirmed. <b>Advanced delay (experimental)</b> is
                                the best-effort fallback for everything else - it watches for that app's own resources
                                loading dynamically and delays those, but some scripts (notably Shopify's own sandboxed
                                marketing pixels - Facebook, TikTok, Klarna, Affirm, and similar) can't be delayed by
                                any method, including this one, since they never appear as literal code anywhere in the
                                page to begin with. Delaying an app that also renders something visible (a reviews
                                widget, chat button, carousel) can make that widget show an error instead of just
                                loading later - worth testing per app. It only works once its tag is added near the top
                                of your theme's &lt;head&gt; (see <b>Advanced delay setup</b> above - Auto-fix or Manual
                                fix, one tag total, not per app).{' '}
                                <b>Manual fix</b> shows
                                you the exact code to paste yourself right now, no approval needed. <b>Excluded</b> just
                                stops it from being flagged here - it doesn't change your storefront. Rows marked{' '}
                                <Badge tone="info">Shopify platform</Badge> are loaded by Shopify itself (Shop Pay,
                                checkout, core analytics), not an installed app - no app can edit these.
                            </Text>
                            {appImpacts.map((impact, i) => (
                                <React.Fragment key={impact.id}>
                                    {i > 0 && <div style={{ borderTop: '1px solid var(--p-color-border-secondary)' }} />}
                                    <ImpactRow
                                        impact={impact}
                                        pending={pendingId === impact.id}
                                        onSetStatus={setStatus}
                                        scriptManager={scriptManager}
                                        onScriptManagerChange={loadScriptManager}
                                    />
                                </React.Fragment>
                            ))}
                        </BlockStack>
                    )}
                </Card>
            </BlockStack>
        </Page>
    );
}
