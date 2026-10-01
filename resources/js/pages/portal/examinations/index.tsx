import { Head } from '@inertiajs/react';
import { CalendarClock, ChartColumn, ClipboardCheck, ClipboardList, Inbox, PartyPopper } from 'lucide-react';
import { BarList } from '@/components/charts/bar-list';
import { ExamCard, type PortalExam } from '@/components/portal/exam-card';
import { PortalEmpty, PortalHeading, PortalSection } from '@/components/portal/portal-ui';
import { Pagination } from '@/components/ui/pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatPercent, useDateFormatter } from '@/lib/format';
import type { Paginated } from '@/types';
import type { CandidateExaminationResult } from '@/types/candidates';

interface ReleasedScore {
    id: number;
    title: string;
    subject: string;
    kind: string;
    attemptNumber: number;
    submittedAt: string | null;
    percentage: number | null;
    passed: boolean | null;
}

interface ExaminationsProps {
    summary: { eligible: boolean };
    available: Paginated<PortalExam>;
    upcoming: Paginated<PortalExam>;
    /** Every own attempt; scores only once released and graded. */
    results: Paginated<CandidateExaminationResult>;
    /** Released, graded scores, oldest first (for the chart). */
    scoreTrend: ReleasedScore[];
}

/** Candidate "Examinations": what is open, what is scheduled, and own results. */
export default function PortalExaminations({ summary, available, upcoming, results, scoreTrend }: ExaminationsProps) {
    const dates = useDateFormatter();

    return (
        <>
            <Head title="Examinations" />
            <PortalHeading icon={ClipboardList} title="Examinations" description="Take open quizzes and examinations, and see your released results." />

            <div className="flex flex-col gap-10">
                <PortalSection icon={ClipboardList} title="Open Now">
                    {available.data.length === 0 ? (
                        <PortalEmpty icon={PartyPopper} title="Nothing to take right now">
                            {summary.eligible
                                ? 'Examinations appear here when your instructor opens them.'
                                : 'An eligible class assignment is needed to take examinations. Please contact the academic office.'}
                        </PortalEmpty>
                    ) : (
                        <div className="grid gap-5 md:grid-cols-2">
                            {available.data.map((exam) => (
                                <ExamCard key={exam.id} exam={exam} />
                            ))}
                        </div>
                    )}
                    <div className="mt-4">
                        <Pagination page={available} noun={{ one: 'examination', other: 'examinations' }} />
                    </div>
                </PortalSection>

                {upcoming.total > 0 && (
                    <PortalSection icon={CalendarClock} title="Coming Up" description="Scheduled for your class. Times use the institution's timezone.">
                        <div className="grid gap-5 md:grid-cols-2">
                            {upcoming.data.map((exam) => (
                                <ExamCard key={exam.id} exam={exam} upcoming />
                            ))}
                        </div>
                        <div className="mt-4">
                            <Pagination page={upcoming} noun={{ one: 'scheduled examination', other: 'scheduled examinations' }} />
                        </div>
                    </PortalSection>
                )}

                {scoreTrend.length > 0 && (
                    <PortalSection icon={ChartColumn} title="My Scores" description="Your released results, oldest first.">
                        <BarList
                            bars={scoreTrend.map((score) => ({ label: `${score.title} · Attempt ${score.attemptNumber}`, value: score.percentage }))}
                            emptyValue="No score"
                            formatValue={(value) => formatPercent(value)}
                        />
                    </PortalSection>
                )}

                <PortalSection icon={ClipboardCheck} title="My Results" description="Scores appear when your instructor releases them; essays may still be under review." flush>
                    {results.data.length === 0 ? (
                        <div className="p-5 sm:p-7">
                            <PortalEmpty icon={Inbox} title="No attempts yet">
                                Your submitted quizzes and examinations appear here.
                            </PortalEmpty>
                        </div>
                    ) : (
                        <>
                            <ul className="divide-y divide-line">
                                {results.data.map((result) => (
                                    <li key={result.id} className="flex flex-wrap items-center justify-between gap-4 px-5 py-5 sm:px-7">
                                        <div className="min-w-0">
                                            <p className="text-base font-semibold text-ink">{result.title}</p>
                                            <p className="mt-0.5 text-sm text-ink-muted">
                                                {result.subject} · Attempt {result.attemptNumber}
                                                {result.submittedAt && ` · ${dates.dateTime(result.submittedAt)}`}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-4">
                                            {result.percentage !== null && (
                                                <span className="text-xl font-bold text-ink tabular-nums">{formatPercent(result.percentage)}</span>
                                            )}
                                            {result.passed === null ? (
                                                <StatusBadge tone="neutral">{result.resultLabel}</StatusBadge>
                                            ) : (
                                                <StatusBadge tone={result.passed ? 'success' : 'danger'}>{result.passed ? 'Passed' : 'Not passed'}</StatusBadge>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                            <Pagination page={results} noun={{ one: 'attempt', other: 'attempts' }} />
                        </>
                    )}
                </PortalSection>
            </div>
        </>
    );
}
