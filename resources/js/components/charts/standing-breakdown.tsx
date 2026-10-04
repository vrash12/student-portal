import type { ReactNode } from 'react';
import { PieChart } from '@/components/charts/pie-chart';
import { cn } from '@/lib/cn';
import { formatGrade } from '@/lib/format';
import type { ChartTone, StandingGroup, StandingTally } from '@/types/charts';

/** Standings in the order shown, with their chart fills. Every bar is also described in text, never by color alone (§47). */
const SERIES: ReadonlyArray<{ key: keyof StandingTally; label: string; fill: string; tone: ChartTone }> = [
    { key: 'passing', label: 'Passing', fill: 'bg-chart-passing', tone: 'passing' },
    { key: 'atRisk', label: 'Needs Improvement', fill: 'bg-chart-at-risk', tone: 'atRisk' },
    { key: 'failing', label: 'Failing', fill: 'bg-chart-failing', tone: 'failing' },
    { key: 'incomplete', label: 'Incomplete', fill: 'bg-chart-incomplete', tone: 'incomplete' },
    { key: 'noStanding', label: 'No standing yet', fill: 'bg-chart-none', tone: 'none' },
];

function totalOf(counts: StandingTally): number {
    return SERIES.reduce((sum, series) => sum + counts[series.key], 0);
}

function percentOf(count: number, total: number): string {
    return total === 0 ? '0%' : `${Math.round((count * 100) / total)}%`;
}

/** "12 Passing · 3 Needs Improvement · 1 Failing": the non-zero standings, in chart order. */
function summaryOf(counts: StandingTally): string {
    const parts = SERIES.filter((series) => counts[series.key] > 0).map((series) => `${counts[series.key]} ${series.label}`);

    return parts.length === 0 ? 'No records' : parts.join(' · ');
}

/** One 100% bar split by standing. Decorative: callers always show the same figures as text. */
export function StandingBar({ counts, className }: { counts: StandingTally; className?: string }) {
    const segments = SERIES.filter((series) => counts[series.key] > 0);

    return (
        <div aria-hidden="true" className={cn('flex h-3 w-full gap-0.5 overflow-hidden rounded-sm bg-chart-track', className)}>
            {segments.map((series) => (
                <div key={series.key} className={cn('min-w-1 basis-0', series.fill)} style={{ flexGrow: counts[series.key] }} />
            ))}
        </div>
    );
}

/**
 * The color key, with each standing's count and share when `counts` is
 * given. "No standing yet" is listed only when it occurs (from `counts`, or
 * `withNoStanding` for a key without counts), so a complete period shows
 * four entries.
 */
export function StandingLegend({ counts, withNoStanding = false }: { counts?: StandingTally; withNoStanding?: boolean }) {
    const total = counts === undefined ? 0 : totalOf(counts);
    const showNoStanding = counts === undefined ? withNoStanding : counts.noStanding > 0;
    const series = SERIES.filter((entry) => entry.key !== 'noStanding' || showNoStanding);

    return (
        <ul className="flex flex-wrap gap-x-5 gap-y-2 text-sm text-ink">
            {series.map((entry) => (
                <li key={entry.key} className="flex items-center gap-2">
                    <span aria-hidden="true" className={cn('size-3 shrink-0 rounded-sm', entry.fill)} />
                    <span>{entry.label}</span>
                    {counts !== undefined && (
                        <span className="text-ink-muted">
                            <span className="font-semibold text-ink tabular-nums">{counts[entry.key]}</span> ({percentOf(counts[entry.key], total)})
                        </span>
                    )}
                </li>
            ))}
        </ul>
    );
}

/**
 * A single population split by standing, as a ring with each standing's
 * count and share beside it. "No standing yet" is listed only when it occurs.
 */
export function StandingDistribution({ counts, noun = { one: 'candidate', other: 'candidates' } }: { counts: StandingTally; noun?: { one: string; other: string } }) {
    return (
        <PieChart
            slices={SERIES.filter((series) => series.key !== 'noStanding' || counts.noStanding > 0).map((series) => ({
                label: series.label,
                value: counts[series.key],
                tone: series.tone,
            }))}
            noun={noun}
            listEmpty
        />
    );
}

interface StandingBreakdownProps {
    groups: StandingGroup[];
    /** Renders a group's label, e.g. as a link; plain text by default. */
    renderLabel?: (group: StandingGroup, index: number) => ReactNode;
    /** What a counted record is, e.g. "candidate". */
    noun: { one: string; other: string };
}

/** One standing bar per group (class, subject), each with its figures in text, and a shared color key. */
export function StandingBreakdown({ groups, renderLabel, noun }: StandingBreakdownProps) {
    return (
        <div className="@container flex flex-col gap-4">
            <ul className="flex flex-col gap-3">
                {groups.map((group, index) => {
                    const total = totalOf(group.counts);

                    return (
                        <li key={`${group.label}-${index}`} className="grid gap-x-4 gap-y-1 @xl:grid-cols-[minmax(0,14rem)_minmax(0,1fr)] @xl:items-start">
                            <div className="min-w-0 break-words text-sm font-medium text-ink">{renderLabel ? renderLabel(group, index) : group.label}</div>
                            <div className="flex min-w-0 flex-col gap-1">
                                <StandingBar counts={group.counts} className="mt-1" />
                                <p className="text-xs text-ink-muted">
                                    <span className="tabular-nums">{total}</span> {total === 1 ? noun.one : noun.other}: {summaryOf(group.counts)}
                                    {group.average !== undefined && (
                                        <>
                                            {' '}
                                            · Mean grade <span className="tabular-nums">{formatGrade(group.average)}</span>
                                        </>
                                    )}
                                </p>
                            </div>
                        </li>
                    );
                })}
            </ul>
            <StandingLegend withNoStanding={groups.some((group) => group.counts.noStanding > 0)} />
        </div>
    );
}
