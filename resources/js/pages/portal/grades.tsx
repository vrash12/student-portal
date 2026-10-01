import { Head } from '@inertiajs/react';
import { BookOpen, ChartColumn, ClipboardClock, GraduationCap, History, Hourglass, UserRound } from 'lucide-react';
import { BarList } from '@/components/charts/bar-list';
import { GradeStatusBadge, OverallStandingValue, StandingCell } from '@/components/grading/standing';
import { PortalEmpty, PortalHeading, PortalSection, StatTile } from '@/components/portal/portal-ui';
import { Pagination } from '@/components/ui/pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatCalendarDate, formatGrade, formatPercent } from '@/lib/format';
import type { Paginated } from '@/types';
import type { CandidateAssessmentResult } from '@/types/candidates';
import type { GradingThresholds, OverallStanding, SubjectGrade } from '@/types/grading';

interface GradesProps {
    summary: { eligible: boolean; className: string | null; period: string | null };
    academics: {
        overall: OverallStanding;
        subjects: Array<{ id: number; code: string; name: string; instructors: string[]; result: SubjectGrade }>;
    };
    /** Passing and warning grades of the class's period; null when not set. */
    thresholds: GradingThresholds | null;
    outstanding: Paginated<{ id: number; title: string; subject: string; category: string; date: string | null }>;
    assessmentHistory: Paginated<CandidateAssessmentResult>;
}

/** Candidate "My Grades": standing, current subject grades, missing scores and history. All grades come from the server. */
export default function PortalGrades({ summary, academics, thresholds, outstanding, assessmentHistory }: GradesProps) {
    const { subjects } = academics;

    return (
        <>
            <Head title="My Grades" />
            <PortalHeading
                icon={GraduationCap}
                title="My Grades"
                description={summary.className === null ? 'Your grades appear once you are assigned to a class.' : `${summary.className}${summary.period ? ` · ${summary.period}` : ''}. Grades in progress may change as more assessments are finalized.`}
            />

            <div className="flex flex-col gap-10">
                <dl className="grid gap-5 md:grid-cols-3">
                    <div className="rounded-2xl border border-line bg-surface p-6 shadow-sm">
                        <dt className="text-sm font-medium text-ink-muted">Overall Standing</dt>
                        <dd className="mt-3">
                            {academics.overall.standing === null ? <span className="text-base text-ink-muted">Not available yet</span> : <OverallStandingValue overall={academics.overall} />}
                        </dd>
                    </div>
                    <StatTile icon={BookOpen} label="Subjects" value={subjects.length} />
                    <StatTile icon={Hourglass} label="Awaiting a Score" value={outstanding.total} hint={outstanding.total > 0 ? 'Ask your instructor about these.' : 'Nothing missing.'} />
                </dl>

                <PortalSection icon={ChartColumn} title="Grades by Subject" description="Your current grade in each subject, out of 100.">
                    {subjects.length === 0 ? (
                        <PortalEmpty icon={BookOpen} title="No subjects yet">
                            Subjects appear once your class assignment is complete. Contact the academic office if this is unexpected.
                        </PortalEmpty>
                    ) : (
                        <div className="flex flex-col gap-8">
                            <BarList
                                bars={subjects.map((subject) => ({ label: subject.name, value: subject.result.grade }))}
                                references={
                                    thresholds === null
                                        ? []
                                        : [
                                              { label: 'Passing grade', value: thresholds.passingGrade },
                                              { label: 'Warning grade', value: thresholds.warningGrade },
                                          ]
                                }
                            />
                            <ul className="grid gap-5 md:grid-cols-2">
                                {subjects.map((subject) => (
                                    <li key={subject.id} className="flex flex-col gap-3 rounded-xl border border-line p-5">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <p className="text-lg font-semibold text-primary-900">{subject.name}</p>
                                                <p className="text-sm text-ink-muted">{subject.code}</p>
                                            </div>
                                            <p className="text-3xl font-bold text-ink tabular-nums">{formatGrade(subject.result.grade)}</p>
                                        </div>
                                        <div>{subject.result.standing !== null ? <StandingCell result={subject.result} /> : <GradeStatusBadge result={subject.result} />}</div>
                                        <p className="inline-flex items-center gap-2 text-sm text-ink-muted">
                                            <UserRound className="size-4" aria-hidden="true" />
                                            {subject.instructors.join(', ') || 'No instructor assigned yet'}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </PortalSection>

                {outstanding.total > 0 && (
                    <PortalSection icon={ClipboardClock} title="Awaiting a Score" description="Finalized assessments with no score recorded for you. Work may be outstanding, or grading may still be pending." flush>
                        <ul className="divide-y divide-line">
                            {outstanding.data.map((item) => (
                                <li key={item.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4 sm:px-7">
                                    <div className="min-w-0">
                                        <p className="text-base font-semibold text-ink">{item.title}</p>
                                        <p className="text-sm text-ink-muted">
                                            {item.subject} · {item.category}
                                            {item.date && ` · ${formatCalendarDate(item.date)}`}
                                        </p>
                                    </div>
                                    <StatusBadge tone="warning">Awaiting score</StatusBadge>
                                </li>
                            ))}
                        </ul>
                        <Pagination page={outstanding} noun={{ one: 'assessment', other: 'assessments' }} />
                    </PortalSection>
                )}

                <PortalSection icon={History} title="Assessment History" description="Your recorded results, newest first. Missing scores are never counted as zero." flush>
                    {assessmentHistory.data.length === 0 ? (
                        <div className="p-5 sm:p-7">
                            <PortalEmpty icon={History} title="No finalized assessments yet" />
                        </div>
                    ) : (
                        <>
                            <ul className="divide-y divide-line">
                                {assessmentHistory.data.map((result) => (
                                    <li key={result.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4 sm:px-7">
                                        <div className="min-w-0">
                                            <p className="text-base font-semibold text-ink">{result.title}</p>
                                            <p className="text-sm text-ink-muted">
                                                {result.subject} · {result.category} · {formatCalendarDate(result.date)}
                                            </p>
                                        </div>
                                        {result.score === null ? (
                                            <StatusBadge tone="neutral">Missing</StatusBadge>
                                        ) : (
                                            <div className="text-right">
                                                <p className="text-xl font-bold text-ink tabular-nums">{result.percentage === null ? '—' : formatPercent(result.percentage)}</p>
                                                <p className="text-sm text-ink-muted tabular-nums">
                                                    {result.score} / {result.maxScore}
                                                </p>
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                            <Pagination page={assessmentHistory} noun={{ one: 'assessment', other: 'assessments' }} />
                        </>
                    )}
                </PortalSection>
            </div>
        </>
    );
}
