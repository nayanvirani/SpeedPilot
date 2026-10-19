import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../api';

/**
 * The persistent right-hand panel from the Soft Plum design (Layout 3) -
 * lives at the App shell level (App.jsx), outside <Routes>, so it stays in
 * place across navigation instead of being rebuilt per page. Deliberately
 * light: one API call, read-only until the merchant picks a quick action.
 */
export default function RightRail() {
    const navigate = useNavigate();
    const [audit, setAudit] = useState(null);

    useEffect(() => {
        api.get('/audits').then((res) => setAudit(res.audits?.[0] ?? null)).catch(() => {});
    }, []);

    const score = audit?.score ?? null;

    return (
        <aside style={{ flex: '0 0 280px', display: 'flex', flexDirection: 'column', gap: '18px' }}>
            <div
                style={{
                    background: 'var(--p-color-bg-surface)', border: '1px solid var(--p-color-border-secondary)',
                    borderRadius: '16px', padding: '22px', display: 'flex', flexDirection: 'column',
                    alignItems: 'center', gap: '10px',
                }}
            >
                <div
                    style={{
                        width: '88px', height: '88px', borderRadius: '50%', display: 'flex',
                        alignItems: 'center', justifyContent: 'center',
                        background: score !== null
                            ? `conic-gradient(var(--sp-accent) ${score}%, var(--sp-surface-2) 0)`
                            : 'var(--sp-surface-2)',
                    }}
                >
                    <div
                        style={{
                            width: '70px', height: '70px', borderRadius: '50%', background: 'var(--p-color-bg-surface)',
                            display: 'flex', alignItems: 'center', justifyContent: 'center',
                        }}
                    >
                        <span className="sp-mono" style={{ fontSize: '22px', fontWeight: 700 }}>{score ?? '—'}</span>
                    </div>
                </div>
                <span style={{ fontSize: '12px', color: 'var(--sp-text-faint)' }}>
                    {audit ? 'latest scan' : 'no scans yet'}
                </span>
            </div>

            <div
                style={{
                    background: 'var(--p-color-bg-surface)', border: '1px solid var(--p-color-border-secondary)',
                    borderRadius: '16px', padding: '18px', display: 'flex', flexDirection: 'column', gap: '10px',
                }}
            >
                <span style={{ fontSize: '12px', fontWeight: 700, color: 'var(--p-color-text-secondary)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                    Quick actions
                </span>
                <button
                    onClick={() => navigate('/')}
                    style={{
                        textAlign: 'left', background: 'transparent', border: '1px solid var(--p-color-border-secondary)',
                        borderRadius: '10px', padding: '10px 14px', fontSize: '13.5px', fontWeight: 600,
                        color: 'var(--p-color-text)', cursor: 'pointer',
                    }}
                >
                    Scan my store
                </button>
                {audit && (
                    <button
                        onClick={() => navigate(`/audits/${audit.id}`)}
                        style={{
                            textAlign: 'left', background: 'transparent', border: '1px solid var(--p-color-border-secondary)',
                            borderRadius: '10px', padding: '10px 14px', fontSize: '13.5px', fontWeight: 600,
                            color: 'var(--p-color-text)', cursor: 'pointer',
                        }}
                    >
                        View latest audit
                    </button>
                )}
            </div>
        </aside>
    );
}
