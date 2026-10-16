import React, { useCallback, useEffect, useState } from 'react';
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

function OptimizationRow({ opt, pending, onViewChange, onRollback, onReapply, onReapplyToCurrentTheme }) {
    const backup = opt.backups?.[0];
    const displayStatus = opt.reverted_at ? 'reverted' : opt.status;

    return (
        <BlockStack gap="150">
            <InlineStack align="space-between" blockAlign="start" gap="300" wrap>
                <BlockStack gap="050">
                    <Text as="span" fontWeight="semibold">{fixTitle(opt)}</Text>
                    {opt.asset_key && (
                        <Text as="span" tone="subdued" breakWord>
                            <span className="sp-mono" style={{ fontSize: '12px' }}>{opt.asset_key}</span>
                        </Text>
                    )}
                </BlockStack>
                <InlineStack gap="200" blockAlign="center">
                    <Badge tone={STATUS_TONE[displayStatus]}>{STATUS_LABEL[displayStatus] ?? displayStatus}</Badge>
                    {opt.risk_tier && (
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
                    {opt.status === 'applied' && !opt.reverted_at && !opt.theme_mismatch && (
                        <Button size="micro" loading={pending} onClick={() => onRollback(opt.id)}>Rollback</Button>
                    )}
                    {(opt.status === 'rolled_back' || opt.reverted_at) && !opt.theme_mismatch && (
                        <Button size="micro" variant="primary" loading={pending} onClick={() => onReapply(opt.id)}>Re-apply</Button>
                    )}
                    {opt.theme_mismatch && (
                        <Button size="micro" variant="primary" loading={pending} onClick={() => onReapplyToCurrentTheme(opt.id)}>
                            Apply to current theme
                        </Button>
                    )}
                </InlineStack>
            </InlineStack>
            {opt.theme_mismatch && (
                <Text as="span" tone="caution">
                    ⚠ Your target theme has changed since this was applied - it's no longer on the theme you're using now.
                </Text>
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
    const [notice, setNotice] = useState(null);

    const load = useCallback(() => {
        setLoading(true);
        return api.get('/optimizations')
            .then((res) => setOptimizations(res.optimizations))
            .finally(() => setLoading(false));
    }, []);

    useEffect(() => { load(); }, [load]);

    async function rollback(id) {
        setPendingId(id);
        try {
            await api.post(`/optimizations/${id}/rollback`);
            await load();
        } finally {
            setPendingId(null);
        }
    }

    async function reapply(id) {
        setPendingId(id);
        try {
            await api.post(`/optimizations/${id}/reapply`);
            await load();
        } finally {
            setPendingId(null);
        }
    }

    async function reapplyToCurrentTheme(id) {
        setPendingId(id);
        setNotice(null);
        try {
            await api.post(`/optimizations/${id}/reapply-to-current-theme`);
            setNotice({ tone: 'success', message: 'Re-applied to your current target theme.' });
            await load();
        } catch (e) {
            setNotice({ tone: 'critical', message: e.body?.error || 'Could not re-apply this to your current theme.' });
        } finally {
            setPendingId(null);
        }
    }

    function viewChange(opt) {
        setShowFullDiff(false);
        setDiffOpt(opt);
    }

    const diffBackup = diffOpt?.backups?.[0];
    const diffSnippet = diffBackup?.snippet;
    const mismatchCount = optimizations.filter((o) => o.theme_mismatch).length;

    return (
        <Page title="Optimizations">
            {notice && (
                <Toast
                    content={notice.message}
                    error={notice.tone === 'critical'}
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
                <ThemeAccessStatus />
                {mismatchCount > 0 && (
                    <Banner tone="warning" title="Your target theme has changed">
                        {`${mismatchCount} applied fix${mismatchCount === 1 ? '' : 'es'} below ${mismatchCount === 1 ? "isn't" : "aren't"} on your `
                            + 'current target theme anymore - use "Apply to current theme" on each to bring them over.'}
                    </Banner>
                )}
                <Card>
                    <InlineStack align="space-between" blockAlign="center" wrap>
                        <Text as="p" tone="subdued">
                            A history of every fix SpeedPilot has applied or rolled back on your store - to
                            choose new fixes, go to a scan's full report instead.
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
                            {optimizations.map((opt, i) => (
                                <React.Fragment key={opt.id}>
                                    {i > 0 && <div style={{ borderTop: '1px solid var(--p-color-border-secondary)' }} />}
                                    <OptimizationRow
                                        opt={opt}
                                        pending={pendingId === opt.id}
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
