import { CircleCheck, CircleX, TriangleAlert, type LucideIcon } from 'lucide-react';
import { AreaResultsTable } from '@/components/performance/area-results-table';
import { QualificationBadge, formatAreaGrade } from '@/components/performance/area-status';
import { cn } from '@/lib/cn';
import type { AreaResultData, AreaStatusValue, CandidateQualificationData, PerformanceAreaSummary } from '@/types/performance';

interface QualificationSummaryProps {
    /** The active areas, in area order (sent once with the page). */
    areas: PerformanceAreaSummary[];
    qualification: CandidateQualificationData;
    /**
     * Shows the class rank. Only for staff with performance.view; the
     * candidate portal never receives a rank, so leave this off there.
     */
    showRank?: boolean;
}

/**
 * One candidate's qualification: the decision with its reasons, the overall
 * score, optionally the class rank, and the result of every area. All
 * figures come from the server's QualificationEngine.
 */
export function QualificationSummary({ areas, qualification, showRank = false }: QualificationSummaryProps) {
    const { overall, qualification: decision } = qualification;
    const rank = qualification.rank ?? null;

    if (areas.length === 0) {
        return (
            <p className="text-sm text-ink-muted">No active performance areas, so qualification is Pending.</p>
        );
    }

    return (
        <div className="flex flex-col gap-5">
            <dl className={cn('grid gap-4', showRank ? 'sm:grid-cols-3' : 'sm:grid-cols-2')}>
                <div className="rounded-lg border border-line-box px-4 py-3">
                    <dt className="text-sm font-medium text-ink-muted">Qualification</dt>
                    <dd className="mt-2">
                        <QualificationBadge status={decision.status} className="px-2.5 py-1 text-sm" />
                    </dd>
                </div>
                <div className="rounded-lg border border-line-box px-4 py-3">
                    <dt className="text-sm font-medium text-ink-muted">Overall Score</dt>
                    <dd className="mt-1 text-2xl font-semibold text-ink tabular-nums">{formatAreaGrade(overall.score)}</dd>
                    {overall.score !== null && !overall.complete && (
                        <dd className="mt-0.5 text-xs text-ink-muted">Partial: a weighted area has no grade yet.</dd>
                    )}
                    {overall.score === null && <dd className="mt-0.5 text-xs text-ink-muted">No weighted area has a grade yet.</dd>}
                </div>
                {showRank && (
                    <div className="rounded-lg border border-line-box px-4 py-3">
                        <dt className="text-sm font-medium text-ink-muted">Class Rank</dt>
                        <dd className="mt-1 text-2xl font-semibold text-ink tabular-nums">{rank === null ? 'Unranked' : rank}</dd>
                        {rank === null && <dd className="mt-0.5 text-xs text-ink-muted">No overall score, or withdrawn.</dd>}
                    </div>
                )}
            </dl>

            <QualificationReasons reasons={decision.reasons} pending={decision.pending} />

            <div className="overflow-hidden rounded-lg border border-line-box">
                <AreaResultsTable areas={areas} results={qualification.areas} />
            </div>
        </div>
    );
}

/** Requirements not met and must-pass areas still waiting for results, each with an icon and text. */
export function QualificationReasons({ reasons, pending }: { reasons: string[]; pending: string[] }) {
    if (reasons.length === 0 && pending.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-3 text-sm">
            {reasons.length > 0 && (
                <div>
                    <p className="font-semibold text-ink">Requirements not met</p>
                    <ul className="mt-1 flex flex-col gap-1">
                        {reasons.map((reason) => (
                            <li key={reason} className="flex items-start gap-2 text-ink">
                                <CircleX className="mt-0.5 size-4 shrink-0 text-danger-fg" aria-hidden="true" />
                                {reason}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {pending.length > 0 && (
                <div>
                    <p className="font-semibold text-ink">Waiting for results</p>
                    <ul className="mt-1 flex flex-col gap-1">
                        {pending.map((area) => (
                            <li key={area} className="flex items-start gap-2 text-ink">
                                <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning-fg" aria-hidden="true" />
                                {area}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

const CHECKLIST: Record<AreaStatusValue, { icon: LucideIcon; text: string; iconClass: string }> = {
    passed: { icon: CircleCheck, text: 'Passed', iconClass: 'text-success-fg' },
    failed: { icon: CircleX, text: 'Not met', iconClass: 'text-danger-fg' },
    incomplete: { icon: TriangleAlert, text: 'Pending: results incomplete', iconClass: 'text-warning-fg' },
    not_yet: { icon: TriangleAlert, text: 'Pending: no results yet', iconClass: 'text-warning-fg' },
};

/**
 * The must-pass areas as a checklist (passed, pending, not met), always in
 * words beside the icon. For the candidate's own view.
 */
export function QualificationChecklist({ areas, results }: { areas: PerformanceAreaSummary[]; results: AreaResultData[] }) {
    const resultsByArea = new Map(results.map((result) => [result.areaId, result]));
    const required = areas.filter((area) => area.mustPass);

    if (required.length === 0) {
        return <p className="text-sm text-ink-muted">No must-pass areas.</p>;
    }

    return (
        <ul className="flex flex-col divide-y divide-line rounded-lg border border-line-box">
            {required.map((area) => {
                const result = resultsByArea.get(area.id);
                const item = CHECKLIST[result?.status.value ?? 'not_yet'];

                return (
                    <li key={area.id} className="flex items-start gap-3 px-4 py-3">
                        <item.icon className={cn('mt-0.5 size-5 shrink-0', item.iconClass)} aria-hidden="true" />
                        <div className="min-w-0 flex-1">
                            <p className="font-medium text-ink">{area.name}</p>
                            <p className="text-sm text-ink-muted">
                                {item.text}
                                {result?.grade !== null && result?.grade !== undefined && (
                                    <>
                                        {' · '}
                                        Grade <span className="tabular-nums">{formatAreaGrade(result.grade)}</span>
                                    </>
                                )}
                            </p>
                            {result?.note && <p className="mt-0.5 text-xs text-ink-muted">{result.note}</p>}
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}
