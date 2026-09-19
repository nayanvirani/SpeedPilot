import React, { useCallback, useEffect, useState } from 'react';
import { Badge, Button, Card, DataTable, Page, SkeletonBodyText } from '@shopify/polaris';
import { api } from '../api';

const STATUS_TONE = { applied: 'success', rolled_back: 'new', recommended: 'info' };

export default function Optimizations() {
    const [optimizations, setOptimizations] = useState([]);
    const [loading, setLoading] = useState(true);
    const [pendingId, setPendingId] = useState(null);

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

    const rows = optimizations.map((opt) => [
        opt.type,
        opt.risk_tier,
        <Badge key={`status-${opt.id}`} tone={STATUS_TONE[opt.status]}>{opt.status}</Badge>,
        opt.applied_at ?? '—',
        opt.status === 'applied' ? (
            <Button key={`rollback-${opt.id}`} size="micro" loading={pendingId === opt.id} onClick={() => rollback(opt.id)}>
                Rollback
            </Button>
        ) : '—',
    ]);

    return (
        <Page title="Optimizations">
            <Card>
                {loading ? (
                    <SkeletonBodyText lines={4} />
                ) : (
                    <DataTable
                        columnContentTypes={['text', 'text', 'text', 'text', 'text']}
                        headings={['Fix', 'Risk tier', 'Status', 'Applied at', 'Action']}
                        rows={rows}
                    />
                )}
            </Card>
        </Page>
    );
}
