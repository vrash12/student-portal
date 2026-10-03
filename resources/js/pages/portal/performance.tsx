import { Head, usePage } from '@inertiajs/react';
import { Award, CalendarCheck, ChartColumn, ListChecks, Medal, Scale, Settings2, ThumbsDown, ThumbsUp, UsersRound } from 'lucide-react';
import { BarList } from '@/components/charts/bar-list';
import { formatPoints, formatNetPoints } from '@/components/conduct/conduct-totals';
import { AreaStatusBadge, QualificationBadge, areaRuleLabel, formatAreaGrade } from '@/components/performance/area-status';
import { QualificationChecklist } from '@/components/performance/qualification-summary';
import { AttendanceBreakdown } from '@/components/portal/attendance-breakdown';
import { PortalEmpty, PortalHeading, PortalSection, StatTile } from '@/components/portal/portal-ui';
import { ClientPagination, useClientPagination } from '@/components/ui/client-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatCalendarDate } from '@/lib/format';
import type { CandidateAttendanceRecord, OwnConduct } from '@/types/candidate-performance';
import type { CandidateQualificationData, PerformanceAreaSummary } from '@/types/performance';

interface MyPerformanceProps {
    candidate: { name: string; number: string; className: string | null; period: string | null };
    /** The active performance areas, in area order. */
    areas: PerformanceAreaSummary[];
    /** The candidate's own results; null without a class. Never contains a rank. */
    result: CandidateQualificationData | null;
    /** Areas assessed by staff only (military fitness): counted in the status and overall score, never listed. */
    staffAssessedAreas: number;
    conduct: OwnConduct;
    attendance: CandidateAttendanceRecord;
}

/**
 * "My Performance" (candidate portal): qualification first, then each area,
 * merits/demerits and attendance. Nothing about other candidates and no
 * class rank; every figure comes from the server.
 */
export default function MyPerformance({ candidate, areas, result, staffAssessedAreas, conduct, attendance }: MyPerformanceProps) {
    const { showFitness } = usePage().props.app.portal;
    const sources = showFitness ? 'your grades, fitness tests, conduct and attendance' : `your grades, conduct and attendance${staffAssessedAreas > 0 ? ', and requirements assessed by staff' : ''}`;
    const evaluated = result !== null && areas.length > 0;
    const resultsByArea = new Map((result?.areas ?? []).map((areaResult) => [areaResult.areaId, areaResult]));
    // Must-pass areas are in the checklist; cards are for the other areas only.
    const otherAreas = areas.filter((area) => !area.mustPass);
    const conductPages = useClientPagination(conduct.entries);

    return (
        <>
            <Head title="My Performance" />
            <PortalHeading
                icon={Award}
                title="My Performance"
                description={`${candidate.className ?? 'No class yet'}${candidate.period ? ` · ${candidate.period}` : ''}. Calculated from ${sources}.`}
            />

            <div className="flex flex-col gap-10">
                <PortalSection icon={ListChecks} title="Qualification" description={evaluated ? 'Every required area must be passed to qualify.' : undefined}>
                    {result === null ? (
                        <PortalEmpty icon={UsersRound} title="No class assigned yet">
                            Your qualification is decided within your class. Contact the academic office if your class is missing.
                        </PortalEmpty>
                    ) : areas.length === 0 ? (
                        <PortalEmpty icon={Settings2} title="Not set up yet">
                            The academic office has not set up the performance areas yet.
                        </PortalEmpty>
                    ) : (
                        <div className="grid gap-8 lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)]">
                            <dl className="flex flex-col gap-5">
                                <div className="rounded-xl border border-line-box bg-surface-muted/60 p-5">
                                    <dt className="text-sm font-medium text-ink-muted">Status</dt>
                                    <dd className="mt-3">
                                        <QualificationBadge status={result.qualification.status} className="px-3 py-1.5 text-base" />
                                    </dd>
                                    <dd className="mt-3 text-sm text-ink">{qualificationExplanation(result)}</dd>
                                </div>
                                <StatTile
                                    icon={Scale}
                                    label="Final Course Grade"
                                    value={formatAreaGrade(result.overall.score)}
                                    hint={
                                        result.overall.score === null
                                            ? 'No area has a grade yet.'
                                            : `${result.overall.complete ? 'Weighted mean of your areas' : 'Partial: some areas have no grade yet'}${staffAssessedAreas > 0 ? ', including requirements assessed by staff' : ''}.`
                                    }
                                />
                            </dl>
                            <div>
                                <h3 className="mb-3 text-base font-semibold text-ink">Required Areas</h3>
                                <QualificationChecklist areas={areas} results={result.areas} />
                            </div>
                        </div>
                    )}
                </PortalSection>

                {evaluated && (
                    <PortalSection icon={ChartColumn} title="My Areas" description="Your grade in each area, out of 100, against that area's passing grade. Required areas are in the checklist above.">
                        <div className="flex flex-col gap-8">
                            <BarList
                                bars={areas.map((area) => ({ label: area.name, value: resultsByArea.get(area.id)?.grade ?? null, marker: area.passingGrade }))}
                                markerLabel="Passing grade of each area"
                                emptyValue="No results yet"
                            />
                            {otherAreas.length > 0 && <ul className="grid gap-5 md:grid-cols-2">
                                {otherAreas.map((area) => {
                                    const areaResult = resultsByArea.get(area.id);

                                    return (
                                        <li key={area.id} className="flex flex-col gap-3 rounded-xl border border-line-box p-5">
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="min-w-0">
                                                    <p className="text-lg font-semibold text-primary-900">{area.name}</p>
                                                    <p className="text-sm text-ink-muted">{areaRuleLabel(area)}</p>
                                                </div>
                                                <p className="text-3xl font-bold text-ink tabular-nums">{formatAreaGrade(areaResult?.grade ?? null)}</p>
                                            </div>
                                            {areaResult && <AreaStatusBadge status={areaResult.status} />}
                                            {areaResult?.note && <p className="text-sm text-ink-muted">{areaResult.note}</p>}
                                        </li>
                                    );
                                })}
                            </ul>}
                        </div>
                    </PortalSection>
                )}

                <PortalSection icon={Medal} title="Merits and Demerits" description="Points recorded by your instructors and officers. Ask the academic office about any entry.">
                    <div className="flex flex-col gap-8">
                        <dl className="grid gap-5 sm:grid-cols-3">
                            <StatTile icon={ThumbsUp} label="Merits" value={formatPoints(conduct.totals.merits)} />
                            <StatTile icon={ThumbsDown} label="Demerits" value={formatPoints(conduct.totals.demerits)} />
                            <StatTile icon={Scale} label="Net" value={formatNetPoints(conduct.totals.net)} />
                        </dl>
                        {conduct.entries.length === 0 ? (
                            <PortalEmpty icon={Medal} title="No merits or demerits yet" />
                        ) : (
                            <div>
                                <ul className="divide-y divide-line rounded-xl border border-line-box" aria-label="Your merits and demerits">
                                    {conductPages.rows.map((entry) => (
                                        <li key={entry.id} className="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <StatusBadge tone={entry.kind.tone}>{entry.kind.label}</StatusBadge>
                                                    <span className="font-medium text-ink">{entry.type}</span>
                                                </div>
                                                <p className="mt-1.5 text-sm text-ink">{entry.reason}</p>
                                                <p className="mt-0.5 text-xs text-ink-muted">{formatCalendarDate(entry.occurredOn)}</p>
                                            </div>
                                            <p className="text-lg font-bold text-ink tabular-nums">
                                                <span aria-hidden="true">{entry.kind.value === 'merit' ? '+' : '−'}</span>
                                                <span className="sr-only">{entry.kind.value === 'merit' ? 'Plus ' : 'Minus '}</span>
                                                {formatPoints(entry.points)}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                                <ClientPagination pagination={conductPages} noun={{ one: 'entry', other: 'entries' }} label="Merit and demerit pages" />
                            </div>
                        )}
                    </div>
                </PortalSection>

                <PortalSection icon={CalendarCheck} title="Attendance" description="Training sessions of your class, latest first.">
                    <AttendanceBreakdown summary={attendance.summary} sessions={attendance.sessions} />
                </PortalSection>
            </div>
        </>
    );
}

/** A sentence for the decision, from the server's reasons and pending areas. */
function qualificationExplanation(result: CandidateQualificationData): string {
    const { status, reasons, pending } = result.qualification;

    switch (status.value) {
        case 'qualified':
            return 'You have passed every required area.';
        case 'not_qualified':
            return `${reasons.join('; ')}.`;
        case 'pending':
            return pending.length > 0 ? `Waiting for results in ${pending.join(', ')}.` : 'Waiting for results.';
    }
}
