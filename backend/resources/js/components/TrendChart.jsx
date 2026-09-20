import React, { useMemo, useState } from 'react';
import { Text } from '@shopify/polaris';

const WIDTH = 640;
const HEIGHT = 220;
const PAD = { top: 16, right: 16, bottom: 28, left: 32 };
const LINE_COLOR = 'var(--sp-accent)';
const GRID_COLOR = 'var(--p-color-border-secondary)';
const TEXT_COLOR = 'var(--p-color-text-secondary)';

/**
 * A single-series score-over-time line - one hue, no legend (a single series
 * names itself via the card title), thin 2px line with rounded caps, hairline
 * recessive gridlines, and a crosshair + tooltip so every value is reachable
 * on hover without cluttering the chart with a label per point.
 */
export default function TrendChart({ points }) {
    const [hoverIndex, setHoverIndex] = useState(null);

    const plot = useMemo(() => {
        if (!points || points.length === 0) return null;

        const innerWidth = WIDTH - PAD.left - PAD.right;
        const innerHeight = HEIGHT - PAD.top - PAD.bottom;
        const stepX = points.length > 1 ? innerWidth / (points.length - 1) : 0;

        const coords = points.map((p, i) => ({
            ...p,
            x: PAD.left + stepX * i,
            y: PAD.top + innerHeight * (1 - Math.max(0, Math.min(100, p.score ?? 0)) / 100),
        }));

        const path = coords.map((c, i) => `${i === 0 ? 'M' : 'L'} ${c.x.toFixed(1)} ${c.y.toFixed(1)}`).join(' ');

        return { coords, path, innerWidth, innerHeight };
    }, [points]);

    if (!plot) {
        return null;
    }

    const hovered = hoverIndex !== null ? plot.coords[hoverIndex] : null;

    function handleMove(e) {
        const rect = e.currentTarget.getBoundingClientRect();
        const relX = ((e.clientX - rect.left) / rect.width) * WIDTH;
        let nearest = 0;
        let nearestDist = Infinity;
        plot.coords.forEach((c, i) => {
            const dist = Math.abs(c.x - relX);
            if (dist < nearestDist) {
                nearestDist = dist;
                nearest = i;
            }
        });
        setHoverIndex(nearest);
    }

    const gridLines = [0, 25, 50, 75, 100];

    return (
        <div style={{ position: 'relative' }}>
            <svg
                viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
                style={{ width: '100%', height: 'auto', display: 'block' }}
                onMouseMove={handleMove}
                onMouseLeave={() => setHoverIndex(null)}
                role="img"
                aria-label="Score trend over time"
            >
                {gridLines.map((v) => {
                    const y = PAD.top + plot.innerHeight * (1 - v / 100);
                    return (
                        <g key={v}>
                            <line x1={PAD.left} y1={y} x2={WIDTH - PAD.right} y2={y} stroke={GRID_COLOR} strokeWidth="1" />
                            <text x={PAD.left - 8} y={y + 4} textAnchor="end" fontSize="11" fill={TEXT_COLOR}>{v}</text>
                        </g>
                    );
                })}

                <path d={plot.path} fill="none" stroke={LINE_COLOR} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />

                {plot.coords.map((c, i) => (
                    <circle key={i} cx={c.x} cy={c.y} r={i === hoverIndex ? 5 : 3} fill={LINE_COLOR} stroke="var(--p-color-bg-surface)" strokeWidth="2" />
                ))}

                {hovered && (
                    <line x1={hovered.x} y1={PAD.top} x2={hovered.x} y2={HEIGHT - PAD.bottom} stroke={GRID_COLOR} strokeWidth="1" />
                )}

                {plot.coords.length > 0 && (
                    <>
                        <text x={plot.coords[0].x} y={HEIGHT - 8} textAnchor="start" fontSize="11" fill={TEXT_COLOR}>
                            {plot.coords[0].label}
                        </text>
                        <text x={plot.coords[plot.coords.length - 1].x} y={HEIGHT - 8} textAnchor="end" fontSize="11" fill={TEXT_COLOR}>
                            {plot.coords[plot.coords.length - 1].label}
                        </text>
                    </>
                )}
            </svg>

            {hovered && (
                <div
                    style={{
                        position: 'absolute',
                        left: `${(hovered.x / WIDTH) * 100}%`,
                        top: 0,
                        transform: 'translate(-50%, -100%)',
                        background: 'var(--p-color-bg-surface-secondary)',
                        border: '1px solid var(--p-color-border-secondary)',
                        borderRadius: '6px',
                        padding: '6px 10px',
                        pointerEvents: 'none',
                        whiteSpace: 'nowrap',
                    }}
                >
                    <Text as="span" fontWeight="semibold">{hovered.score ?? '—'}</Text>
                    <Text as="span" tone="subdued"> · {hovered.label}</Text>
                </div>
            )}
        </div>
    );
}
