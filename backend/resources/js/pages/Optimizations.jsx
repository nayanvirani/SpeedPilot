import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Badge, Banner, BlockStack, Box, Button, Card, InlineStack, Modal, Page, SkeletonBodyText, Text, Toast } from '@shopify/polaris';
import { api } from '../api';
import ThemeAccessStatus from '../components/ThemeAccessStatus';

const STATUS_TONE = { applied: 'success', rolled_back: 'new', recommended: 'info', reverted: 'warning' };
const STATUS_LABEL = { applied: 'Applied', rolled_back: 'Rolled back', recommended: 'Recommended', reverted: 'Reverted' };

// Only a handful of fix types have no audit_issue (so no ready-made,
// already-human title from the scanner to fall back on) - everything else
// (lazy-load, font-display, defer/minify CSS...) already arrives as a
// plain-language title like "Render-blocking stylesheet: main.css".
const FIX_TYPE_LABEL = {
    interceptor_install: 'Advanced delay - setup tag',
    prefetch_install: 'Instant navigation - setup tag',
    disabled: 'App disabled',
    delayed: 'App delayed',
    content_stop: 'App stopped (verified)',
};

function fixTitle(opt) {
    return opt.audit_issue?.title ?? opt.app_impact?.app_name ?? FIX_TYPE_LABEL[opt.type] ?? opt.type;
}

function formatWhen(iso) {
    if (!iso) return '—';

    return new Date(iso).toLocaleString(undefined, {
        month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
    });
}

// Theme switches and re-testing naturally leave several rows for the same
// file+fix over time (the Optimizations list is an append-only history, by
// design) - grouping by what a merchant actually sees as "the same fix"
// (its type + which file it touches) keeps the page from reading as
// unexplained duplication, showing only the latest attempt per group with
// older ones tucked behind an optional "earlier attempts" toggle.
function groupKey(opt) {
    return `${opt.type}:${opt.asset_key ?? opt.app_impact_id ?? opt.id}`;
}

function CodeBlock({ label, content, tone }) {
    return (
        <div style={{ flex: 1, minWidth: 0 }}>
            <Text as="p" fontWeight="semibold">{label}</Text>
            <div className={tone === 'before' ? 'sp-diff-before' : 'sp-diff-after'} style={{ borderRadius: '8px', padding: '12px' }}>
                <pre className="sp-mono" style={{ margin: 0, whiteSpace: 'pre-wrap', wordBreak: 'break-word', fontSize: '12px', maxHeight: '400px', overflow: 'auto' }}>
                    {content ?? '(empty)'}
                </pre>
            </div>
        </div>
    );
}

function OptimizationRow({ opt, pending, error, readOnly, onViewChange, onRollback, onReapply, onReapplyToCurrentTheme }) {
    const backup = opt.backups?.[0];
    const displayStatus = opt.reverted_at ? 'reverted' : opt.status;

    return (
        <BlockStack gap="150">
            <InlineStack align="space-between" blockAlign="start" gap="300" wrap>
                <BlockStack gap="050">
                    <Text as="span" fontWeight={readOnly ? 'regular' : 'semibold'}>{fixTitle(opt)}</Text>
                    {opt.asset_key && (
                        <Text as="span" tone="subdued" breakWord>
                            <span className="sp-mono" style={{ fontSize: '12px' }}>{opt.asset_key}</span>
                        </Text>
                    )}
                </BlockStack>
                <InlineStack gap="200" blockAlign="center">
                    <Badge tone={STATUS_TONE[displayStatus]}>{STATUS_LABEL[displayStatus] ?? displayStatus}</Badge>
                    {!readOnly && opt.risk_tier && (
                        <Badge tone={opt.risk_tier === 'safe' ? 'success' : 'warning'}>
                            {opt.risk_tier === 'safe' ? '🟢 Safe' : '🟡 Review'}
                        </Badge>
                    )}
                </InlineStack>
            </InlineStack>
            <InlineStack align="space-between" blockAlign="center" gap="300" wrap>
                <Text as="span" tone="subdued">{formatWhen(opt.applied_at)}</Text>
                <InlineStack gap="200">
                    {backup && <Button size="micro" onClick={() => onViewChange(opt)}>View change</Button>}
                    {!readOnly && opt.status === 'applied' && !opt.reverted_at && !opt.theme_mismatch && (
                        <Button size="micro" loading={pending} onClick={() => onRollback(opt.id)}>Rollback</Button>
                    )}
                    {!readOnly && (opt.status === 'rolled_back' || opt.reverted_at) && !opt.theme_mismatch && (
                        <Button size="micro" variant="primary" loading={pending} onClick={() => onReapply(opt.id)}>Re-apply</Button>
                    )}
                    {!readOnly && opt.theme_mismatch && (
                        <Button size="micro" variant="primary" loading={pending} onClick={() => onReapplyToCurrentTheme(opt.id)}>
                            Apply to current theme
                        </Button>
                    )}
                </InlineStack>
            </InlineStack>
            {!readOnly && opt.theme_mismatch && (
                <Text as="span" tone="caution">
                    ⚠ Your target theme has changed since this was applied - it's no longer on the theme you're using now.
                </Text>
            )}
            {error && <Text as="span" tone="critical">⚠ {error}</Text>}
        </BlockStack>
    );
}

function OptimizationGroup({ group, pendingId, errors, onViewChange, onRollback, onReapply, onReapplyToCurrentTheme }) {
    const [expanded, setExpanded] = useState(false);
    const [latest, ...earlier] = group;

    return (
        <BlockStack gap="200">
            <OptimizationRow
                opt={latest}
                pending={pendingId === latest.id}
                error={errors[latest.id]}
                onViewChange={onViewChange}
                onRollback={onRollback}
                onReapply={onReapply}
                onReapplyToCurrentTheme={onReapplyToCurrentTheme}
            />
            {earlier.length > 0 && (
                <Box paddingInlineStart="400">
                    <BlockStack gap="200">
                        <Button size="micro" variant="plain" onClick={() => setExpanded((v) => !v)}>
                            {expanded ? 'Hide earlier attempts' : `${earlier.length} earlier attempt${earlier.length === 1 ? '' : 's'}`}
                        </Button>
                        {expanded && earlier.map((opt) => (
                            <div key={opt.id} style={{ opacity: 0.75 }}>
                                <OptimizationRow opt={opt} readOnly onViewChange={onViewChange} />
                            </div>
                        ))}
                    </BlockStack>
                </Box>
            )}
        </BlockStack>
    );
}

export default function Optimizations() {
    const navigate = useNavigate();
    const [optimizations, setOptimizations] = useState([]);
    const [loading, setLoading] = useState(true);
    const [pendingId, setPendingId] = useState(null);
    const [diffOpt, setDiffOpt] = useState(null);
    const [showFullDiff, setShowFullDiff] = useState(false);
    const [toast, setToast] = useState(null);
    const [errors, setErrors] = useState({});
    const [resyncingAll, setResyncingAll] = useState(false);

    const load = useCallback(() => {
        setLoading(true);
        return api.get('/optimizations')
            .then((res) => setOptimizations(res.optimizations))
            .finally(() => setLoading(false));
    }, []);

    useEffect(() => { load(); }, [load]);

    async function rollback(id) {
        setPendingId(id);
        setErrors((e) => ({ ...e, [id]: null }));
        try {
            await api.post(`/optimizations/${id}/rollback`);
            await load();
        } catch (e) {
            setErrors((prev) => ({ ...prev, [id]: e.body?.error || 'Could not roll this back.' }));
        } finally {
            setPendingId(null);
        }
    }

    async function reapply(id) {
        setPendingId(id);
        setErrors((e) => ({ ...e, [id]: null }));
        try {
            await api.post(`/optimizations/${id}/reapply`);
            await load();
        } catch (e) {
            setErrors((prev) => ({ ...prev, [id]: e.body?.error || 'Could not re-apply this.' }));
        } finally {
            setPendingId(null);
        }
    }

    async function reapplyToCurrentTheme(id) {
        setPendingId(id);
        setErrors((e) => ({ ...e, [id]: null }));
        try {
            await api.post(`/optimizations/${id}/reapply-to-current-theme`);
            setToast({ tone: 'success', message: 'Re-applied to your current target theme.' });
            await load();
        } catch (e) {
            setErrors((prev) => ({ ...prev, [id]: e.body?.error || 'Could not re-apply this to your current theme.' }));
        } finally {
            setPendingId(null);
        }
    }

    async function reapplyAllToCurrentTheme() {
        setResyncingAll(true);
        try {
            const res = await api.post('/optimizations/reapply-all-to-current-theme');
            const results = res.results ?? [];
            const failed = results.filter((r) => !r.success);
            setErrors((prev) => {
                const next = { ...prev };
                for (const r of failed) next[r.original_id] = r.error;
                return next;
            });
            setToast({
                tone: failed.length > 0 ? 'critical' : 'success',
                message: failed.length === 0
                    ? `Re-applied ${results.length} fix${results.length === 1 ? '' : 'es'} to your current theme.`
                    : `Re-applied ${results.length - failed.length} of ${results.length} - ${failed.length} need attention below.`,
            });
            await load();
        } catch (e) {
            setToast({ tone: 'critical', message: e.body?.error || 'Could not resync these fixes right now.' });
        } finally {
            setResyncingAll(false);
        }
    }

    function viewChange(opt) {
        setShowFullDiff(false);
        setDiffOpt(opt);
    }

    const diffBackup = diffOpt?.backups?.[0];
    const diffSnippet = diffBackup?.snippet;

    // optimizations already arrives newest-first (GET /optimizations
    // orders by latest()), so within each group the first item encountered
    // is naturally the most recent attempt.
    const groups = useMemo(() => {
        const map = new Map();
        for (const opt of optimizations) {
            const key = groupKey(opt);
            if (!map.has(key)) map.set(key, []);
            map.get(key).push(opt);
        }

        return Array.from(map.entries());
    }, [optimizations]);

    // Counts only the latest attempt per group, matching what "Apply All to
    // Current Theme" actually acts on (it also dedupes to the latest
    // applied row per logical fix) - so the button's number always matches
    // its real effect.
    const mismatchCount = groups.filter(([, group]) => group[0].theme_mismatch).length;

    return (
        <Page title="Optimizations">
            {toast && (
                <Toast
                    content={toast.message}
                    error={toast.tone === 'critical'}
                    onDismiss={() => setToast(null)}
                    duration={3000}
                />
            )}
            <BlockStack gap="400">
                <ThemeAccessStatus />
                {mismatchCount > 0 && (
                    <Banner tone="warning" title="Your target theme has changed">
                        <BlockStack gap="200">
                            <Text as="p">
                                {`${mismatchCount} applied fix${mismatchCount === 1 ? '' : 'es'} below ${mismatchCount === 1 ? "isn't" : "aren't"} on your `
                                    + 'current target theme anymore.'}
                            </Text>
                            <InlineStack>
                                <Button loading={resyncingAll} onClick={reapplyAllToCurrentTheme}>
                                    {resyncingAll ? 'Resyncing…' : `Apply All ${mismatchCount} to Current Theme`}
                                </Button>
                            </InlineStack>
                        </BlockStack>
                    </Banner>
                )}
                <Card>
                    <InlineStack align="space-between" blockAlign="center" wrap>
                        <Text as="p" tone="subdued">
                            A history of every fix SpeedPilot has applied or rolled back on your store - to
                            choose new fixes, go to a scan's full report instead. Each fix shows its most
                            recent attempt; older attempts for the same fix are tucked behind "earlier
                            attempts" rather than listed as separate entries.
                        </Text>
                        <Button onClick={() => navigate('/')}>Go to Dashboard</Button>
                    </InlineStack>
                </Card>
                <Card>
                    {loading ? (
                        <SkeletonBodyText lines={4} />
                    ) : optimizations.length === 0 ? (
                        <BlockStack gap="200">
                            <Text as="h2" variant="headingMd">Nothing applied yet</Text>
                            <Text as="p" tone="subdued">
                                This fills in once a scan finds a "safe" tier issue SpeedPilot can fix on
                                its own (like a render-blocking script or an unloaded image). Issues found
                                so far were higher-risk or recommendation-only - check the audit's full
                                report for those.
                            </Text>
                        </BlockStack>
                    ) : (
                        <BlockStack gap="400">
                            {groups.map(([key, group], i) => (
                                <React.Fragment key={key}>
                                    {i > 0 && <div style={{ borderTop: '1px solid var(--p-color-border-secondary)' }} />}
                                    <OptimizationGroup
                                        group={group}
                                        pendingId={pendingId}
                                        errors={errors}
                                        onViewChange={viewChange}
                                        onRollback={rollback}
                                        onReapply={reapply}
                                        onReapplyToCurrentTheme={reapplyToCurrentTheme}
                                    />
                                </React.Fragment>
                            ))}
                        </BlockStack>
                    )}
                </Card>
            </BlockStack>

            <Modal
                open={!!diffOpt}
                onClose={() => setDiffOpt(null)}
                title={diffOpt ? fixTitle(diffOpt) : ''}
                size="large"
            >
                <Modal.Section>
                    {diffOpt?.asset_key && (
                        <Box paddingBlockEnd="200">
                            <Text as="span" tone="subdued"><span className="sp-mono">{diffOpt.asset_key}</span></Text>
                        </Box>
                    )}
                    {diffOpt?.audit_issue?.description && (
                        <Box paddingBlockEnd="400">
                            <Text as="p" tone="subdued">{diffOpt.audit_issue.description}</Text>
                        </Box>
                    )}
                    {diffSnippet && !showFullDiff && !diffSnippet.unchanged && (
                        <Box paddingBlockEnd="300">
                            <Text as="p" tone="subdued">
                                Around line {diffSnippet.start_line} of the file - only the changed part is shown,
                                with a little surrounding text to help you locate it.
                            </Text>
                        </Box>
                    )}
                    <div style={{ display: 'flex', gap: '16px', flexWrap: 'wrap' }}>
                        <CodeBlock
                            label="Original"
                            tone="before"
                            content={diffSnippet && !showFullDiff ? diffSnippet.before : diffBackup?.original_content}
                        />
                        <CodeBlock
                            label="After the fix"
                            tone="after"
                            content={diffSnippet && !showFullDiff ? diffSnippet.after : diffBackup?.updated_content}
                        />
                    </div>
                    {!diffBackup?.updated_content && (
                        <Box paddingBlockStart="300">
                            <Text as="p" tone="subdued">
                                This fix was applied before change logging was added, so only the
                                original content was saved.
                            </Text>
                        </Box>
                    )}
                    {diffSnippet && !diffSnippet.unchanged && (
                        <Box paddingBlockStart="300">
                            <InlineStack align="end">
                                <Button size="micro" variant="tertiary" onClick={() => setShowFullDiff((v) => !v)}>
                                    {showFullDiff ? 'Show just the changed part' : 'Show the whole file instead'}
                                </Button>
                            </InlineStack>
                        </Box>
                    )}
                </Modal.Section>
            </Modal>
        </Page>
    );
}
