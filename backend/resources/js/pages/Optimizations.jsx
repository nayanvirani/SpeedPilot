import React, { useCallback, useEffect, useState } from 'react';
import { Badge, Box, Button, Card, DataTable, Modal, Page, SkeletonBodyText, Text } from '@shopify/polaris';
import { api } from '../api';

const STATUS_TONE = { applied: 'success', rolled_back: 'new', recommended: 'info' };

function CodeBlock({ label, content, tone }) {
    return (
        <div style={{ flex: 1, minWidth: 0 }}>
            <Text as="p" fontWeight="semibold">{label}</Text>
            <Box
                background={tone === 'before' ? 'bg-surface-critical' : 'bg-surface-success'}
                padding="300"
                borderRadius="200"
            >
                <pre style={{ margin: 0, whiteSpace: 'pre-wrap', wordBreak: 'break-word', fontSize: '12px', maxHeight: '400px', overflow: 'auto' }}>
                    {content ?? '(empty)'}
                </pre>
            </Box>
        </div>
    );
}

export default function Optimizations() {
    const [optimizations, setOptimizations] = useState([]);
    const [loading, setLoading] = useState(true);
    const [pendingId, setPendingId] = useState(null);
    const [diffOpt, setDiffOpt] = useState(null);

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

    const rows = optimizations.map((opt) => {
        const backup = opt.backups?.[0];

        return [
            opt.audit_issue?.title ?? opt.type,
            opt.asset_key ?? '—',
            opt.risk_tier,
            <Badge key={`status-${opt.id}`} tone={STATUS_TONE[opt.status]}>{opt.status}</Badge>,
            opt.applied_at ?? '—',
            <div key={`actions-${opt.id}`} style={{ display: 'flex', gap: '8px' }}>
                {backup && (
                    <Button size="micro" onClick={() => setDiffOpt(opt)}>View change</Button>
                )}
                {opt.status === 'applied' && (
                    <Button size="micro" loading={pendingId === opt.id} onClick={() => rollback(opt.id)}>
                        Rollback
                    </Button>
                )}
            </div>,
        ];
    });

    const diffBackup = diffOpt?.backups?.[0];

    return (
        <Page title="Optimizations">
            <Card>
                {loading ? (
                    <SkeletonBodyText lines={4} />
                ) : (
                    <DataTable
                        columnContentTypes={['text', 'text', 'text', 'text', 'text', 'text']}
                        headings={['Fix', 'File changed', 'Risk tier', 'Status', 'Applied at', 'Actions']}
                        rows={rows}
                    />
                )}
            </Card>

            <Modal
                open={!!diffOpt}
                onClose={() => setDiffOpt(null)}
                title={diffOpt ? `Change to ${diffOpt.asset_key}` : ''}
                size="large"
            >
                <Modal.Section>
                    {diffOpt?.audit_issue?.description && (
                        <Box paddingBlockEnd="400">
                            <Text as="p" tone="subdued">{diffOpt.audit_issue.description}</Text>
                        </Box>
                    )}
                    <div style={{ display: 'flex', gap: '16px' }}>
                        <CodeBlock label="Original" tone="before" content={diffBackup?.original_content} />
                        <CodeBlock label="After the fix" tone="after" content={diffBackup?.updated_content} />
                    </div>
                    {!diffBackup?.updated_content && (
                        <Box paddingBlockStart="300">
                            <Text as="p" tone="subdued">
                                This fix was applied before change logging was added, so only the
                                original content was saved.
                            </Text>
                        </Box>
                    )}
                </Modal.Section>
            </Modal>
        </Page>
    );
}
