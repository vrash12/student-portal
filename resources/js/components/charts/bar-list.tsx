import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { formatGrade } from '@/lib/format';
import type { ChartBar, ChartReference } from '@/types/charts';

/** Line styles of up to two reference markers, in order: e.g. passing grade (solid), warning grade (dashed). */
const REFERENCE_STYLES = ['border-solid', 'border-dashed'] as const;

function clampPercent(value: number): number {
    return Math.min(100, Math.max(0, value));
}

interface BarListProps {
    bars: ChartBar[];
    references?: ChartReference[];
    /** Renders a bar's label, e.g. as a link; plain text by default. */
    renderLabel?: (bar: ChartBar, index: number) => ReactNode;
    /** Text shown instead of a value when a bar has none. */
    emptyValue?: string;
    /** Formats a bar's value; two decimals by default (grades). */
    formatValue?: (value: number) => string;
}

/**
 * Horizontal bars on a fixed 0–100 scale (grades and percentages), each
 * labelled with its value, with optional reference lines such as the passing
 * and warning grades. Values are printed beside every bar.
 */
export function BarList({ bars, references = [], renderLabel, emptyValue = 'No grades yet', formatValue = formatGrade }: BarListProps) {
    const markers = references.slice(0, REFERENCE_STYLES.length);

    return (
        <div className="@container flex flex-col gap-4">
            <ul className="flex flex-col gap-3">
                {bars.map((bar, index) => (
                    <li
                        key={`${bar.label}-${index}`}
                        className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 @xl:grid-cols-[minmax(0,14rem)_minmax(0,1fr)_5rem]"
                    >
                        <div className="min-w-0 break-words text-sm font-medium text-ink">{renderLabel ? renderLabel(bar, index) : bar.label}</div>
                        <div aria-hidden="true" className="relative col-span-2 row-start-2 h-3 rounded-sm bg-chart-track @xl:col-span-1 @xl:row-start-auto">
                            {bar.value !== null && (
                                <div className="absolute inset-y-0 left-0 rounded-sm bg-chart-bar" style={{ width: `${clampPercent(bar.value)}%` }} />
                            )}
                            {markers.map((reference, markerIndex) => (
                                <div
                                    key={reference.label}
                                    className={cn('absolute -inset-y-1 border-l-2 border-ink', REFERENCE_STYLES[markerIndex])}
                                    style={{ left: `${clampPercent(reference.value)}%` }}
                                />
                            ))}
                        </div>
                        <div className="text-right text-sm font-semibold text-ink tabular-nums">
                            {bar.value === null ? <span className="font-normal text-ink-muted">{emptyValue}</span> : formatValue(bar.value)}
                        </div>
                    </li>
                ))}
            </ul>
            {markers.length > 0 && (
                <ul className="flex flex-wrap gap-x-5 gap-y-2 text-sm text-ink">
                    {markers.map((reference, markerIndex) => (
                        <li key={reference.label} className="flex items-center gap-2">
                            <span aria-hidden="true" className={cn('h-4 w-0 border-l-2 border-ink', REFERENCE_STYLES[markerIndex])} />
                            {reference.label} <span className="tabular-nums">{formatGrade(reference.value)}</span>
                        </li>
                    ))}
                    <li className="text-ink-muted">Scale 0–100</li>
                </ul>
            )}
        </div>
    );
}
