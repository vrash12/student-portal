import { useState } from 'react';
import { toneAt, toneClasses } from '@/components/charts/chart-colors';
import { cn } from '@/lib/cn';
import type { PieSlice } from '@/types/charts';

const RADIUS = 40;
const RING = 17;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;
/** White space between slices, in units of the ring's length. */
const GAP = 1.4;

interface PieChartProps {
    slices: PieSlice[];
    /** What a counted record is, e.g. "candidate"; shown under the total. */
    noun: { one: string; other: string };
    /** Formats values (e.g. amounts of money); whole counts by default. */
    formatValue?: (value: number) => string;
    /**
     * Lists slices without records in the legend too, for fixed sets such as
     * the standings; other slices without records are left out.
     */
    listEmpty?: boolean;
    /** Shows each slice's share beside its value; off when the values already are shares (weights adding up to 100%). */
    showShare?: boolean;
    className?: string;
}

function percentOf(value: number, total: number): string {
    return total === 0 ? '0%' : `${Math.round((value * 100) / total)}%`;
}

/**
 * A share of a whole as a ring (donut) with the total in the middle. The
 * legend beside it gives every slice's label, value and share in text, so
 * nothing depends on color (UI_UX_DESIGN.md §47). Drawn as SVG: no chart
 * library, works offline, prints in color. Values come from the server;
 * only the shares are worked out here, for display.
 */
export function PieChart({ slices, noun, formatValue = (value) => String(value), listEmpty = false, showShare = true, className }: PieChartProps) {
    const [active, setActive] = useState<number | null>(null);
    const colored = slices.map((slice, index) => ({ ...slice, tone: toneAt(index, slice.tone), index }));
    const total = colored.reduce((sum, slice) => sum + Math.max(0, slice.value), 0);
    const drawn = colored.filter((slice) => slice.value > 0);
    const listed = listEmpty ? colored : drawn;
    const focus = active === null ? null : (colored[active] ?? null);

    if (total === 0) {
        return <p className={cn('text-sm text-ink-muted', className)}>No records yet.</p>;
    }

    let start = 0;
    const arcs = drawn.map((slice) => {
        const length = (slice.value / total) * CIRCUMFERENCE;
        const arc = { slice, start, length: drawn.length === 1 ? CIRCUMFERENCE : Math.max(length - GAP, 0.4) };
        start += length;

        return arc;
    });

    return (
        <div className={cn('@container [-webkit-print-color-adjust:exact] [print-color-adjust:exact]', className)}>
            <div className="flex flex-col items-center gap-5 @sm:flex-row @sm:items-center">
                <div className="relative size-40 shrink-0" onMouseLeave={() => setActive(null)}>
                    <svg viewBox="0 0 100 100" className="size-full -rotate-90" aria-hidden="true">
                        <circle cx="50" cy="50" r={RADIUS} fill="none" strokeWidth={RING} className="stroke-chart-track" />
                        {arcs.map(({ slice, start: offset, length }) => (
                            <circle
                                key={`${slice.label}-${slice.index}`}
                                cx="50"
                                cy="50"
                                r={RADIUS}
                                fill="none"
                                strokeWidth={active === slice.index ? RING + 3 : RING}
                                strokeDasharray={`${length} ${CIRCUMFERENCE - length}`}
                                strokeDashoffset={-offset}
                                className={cn(
                                    toneClasses(slice.tone).stroke,
                                    'transition-opacity duration-150',
                                    active !== null && active !== slice.index && 'opacity-35',
                                )}
                                onMouseEnter={() => setActive(slice.index)}
                            />
                        ))}
                    </svg>
                    <div aria-hidden="true" className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center px-7 text-center">
                        {focus === null ? (
                            <>
                                <span className="text-2xl font-semibold leading-tight text-ink tabular-nums">{formatValue(total)}</span>
                                <span className="text-xs text-ink-muted">{total === 1 ? noun.one : noun.other}</span>
                            </>
                        ) : (
                            <>
                                <span className="text-2xl font-semibold leading-tight text-ink tabular-nums">{percentOf(focus.value, total)}</span>
                                <span className="line-clamp-2 text-xs text-ink-muted">{focus.label}</span>
                            </>
                        )}
                    </div>
                </div>

                <ul className="flex w-full min-w-0 flex-col gap-2 text-sm @sm:max-w-sm @sm:flex-1">
                    {listed.map((slice) => (
                        <li
                            key={`${slice.label}-${slice.index}`}
                            className={cn(
                                'flex items-center gap-2.5 rounded-md px-2 py-1 transition-colors',
                                active === slice.index && 'bg-surface-muted',
                            )}
                            onMouseEnter={() => setActive(slice.value > 0 ? slice.index : null)}
                            onMouseLeave={() => setActive(null)}
                        >
                            <span aria-hidden="true" className={cn('size-3 shrink-0 rounded-sm', toneClasses(slice.tone).bg)} />
                            <span className="min-w-0 flex-1 break-words text-ink">{slice.label}</span>
                            <span className="shrink-0 text-right tabular-nums">
                                <span className="font-semibold text-ink">{formatValue(slice.value)}</span>
                                {showShare && <span className="text-ink-muted"> ({percentOf(slice.value, total)})</span>}
                            </span>
                        </li>
                    ))}
                    <li className="sr-only">
                        Total {formatValue(total)} {total === 1 ? noun.one : noun.other}
                    </li>
                </ul>
            </div>
        </div>
    );
}
