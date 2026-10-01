import { cn } from '@/lib/cn';
import type { QualificationCounts } from '@/types/performance';

/** Statuses in the order shown, with their chart fills. Every segment is also given in text, never by colour alone (§47). */
const SERIES: ReadonlyArray<{ key: 'qualified' | 'pending' | 'notQualified'; label: string; fill: string }> = [
    { key: 'qualified', label: 'Qualified', fill: 'bg-chart-passing' },
    { key: 'pending', label: 'Pending', fill: 'bg-chart-at-risk' },
    { key: 'notQualified', label: 'Not Qualified', fill: 'bg-chart-failing' },
];

function percentOf(count: number, total: number): string {
    return total === 0 ? '0%' : `${Math.round((count * 100) / total)}%`;
}

/**
 * Candidates per qualification status as one 100% bar with a legend giving
 * each status's count and share. Counts come from the server.
 */
export function QualificationDistribution({ counts }: { counts: QualificationCounts }) {
    const segments = SERIES.filter((series) => counts[series.key] > 0);

    return (
        <div className="flex flex-col gap-3 [-webkit-print-color-adjust:exact] [print-color-adjust:exact]">
            <div aria-hidden="true" className="flex h-4 w-full gap-0.5 overflow-hidden rounded-sm bg-chart-track">
                {segments.map((series) => (
                    <div key={series.key} className={cn('min-w-1 basis-0', series.fill)} style={{ flexGrow: counts[series.key] }} />
                ))}
            </div>
            <ul className="flex flex-wrap gap-x-5 gap-y-2 text-sm text-ink">
                {SERIES.map((series) => (
                    <li key={series.key} className="flex items-center gap-2">
                        <span aria-hidden="true" className={cn('size-3 shrink-0 rounded-sm', series.fill)} />
                        <span>{series.label}</span>
                        <span className="text-ink-muted">
                            <span className="font-semibold text-ink tabular-nums">{counts[series.key]}</span> ({percentOf(counts[series.key], counts.total)})
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
