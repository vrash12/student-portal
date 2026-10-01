import { cn } from '@/lib/cn';
import type { ChartColumn } from '@/types/charts';

interface ColumnChartProps {
    columns: ChartColumn[];
    /** What a counted record is, e.g. "attempt". */
    noun: { one: string; other: string };
}

/**
 * A distribution as vertical columns (grade ranges, score ranges). Each
 * column shows its count above and its range below; screen readers get the
 * same figures as a list.
 */
export function ColumnChart({ columns, noun }: ColumnChartProps) {
    const highest = Math.max(1, ...columns.map((column) => column.value));

    return (
        <div className="flex flex-col gap-1">
            <div aria-hidden="true" className="flex h-40 items-end gap-2 border-b border-line-strong">
                {columns.map((column, index) => (
                    <div key={`${column.label}-${index}`} className="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1">
                        <span className="text-xs font-semibold text-ink tabular-nums">{column.value}</span>
                        <div
                            className={cn('w-full max-w-16 rounded-t-sm', column.muted ? 'bg-chart-none' : 'bg-chart-bar')}
                            style={{ height: column.value === 0 ? 0 : `max(${(column.value * 100) / highest}%, 3px)` }}
                        />
                    </div>
                ))}
            </div>
            <div aria-hidden="true" className="flex gap-2">
                {columns.map((column, index) => (
                    <span key={`${column.label}-${index}`} className={cn('min-w-0 flex-1 break-words text-center text-xs', column.muted ? 'text-ink-subtle' : 'text-ink-muted')}>
                        {column.label}
                    </span>
                ))}
            </div>
            <ul className="sr-only">
                {columns.map((column, index) => (
                    <li key={`${column.label}-${index}`}>
                        {column.label}: {column.value} {column.value === 1 ? noun.one : noun.other}
                    </li>
                ))}
            </ul>
        </div>
    );
}
