import { Link } from '@inertiajs/react';
import { History } from 'lucide-react';
import { OverallStandingValue } from '@/components/grading/standing';
import { EmptyState } from '@/components/ui/empty-state';
import { Panel } from '@/components/ui/panel';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatCalendarDate, formatGrade, formatPercent, useDateFormatter } from '@/lib/format';
import type { OverallStanding } from '@/types/grading';
import type { SubjectConcern } from '@/types/monitoring';

/** A candidate's result on one finalized assessment. */
export interface AssessmentResult {
    assessment: { id: number; title: string };
    subject: string;
    category: string;
    /** The assessment date, or the finalization date for undated assessments. */
    date: string | null;
    maxScore: string;
    /** Null when the candidate has no score on this finalized assessment (missing). */
    score: string | null;
    percentage: number | null;
}

export interface SubjectResults {
    classSubjectId: number;
    subject: { code: string; name: string };
    assessments: AssessmentResult[];
}

export type ActivityEntry =
    | ({ type: 'finalized'; id: string; at: string | null } & AssessmentResult)
    | {
          type: 'corrected';
          id: string;
          at: string | null;
          assessment: { id: number; title: string };
          subject: string;
          previousScore: string | null;
          newScore: string | null;
          maxScore: string;
          reason: string | null;
          changedBy: string;
      };

interface AcademicSummaryProps {
    label: string;
    overall: OverallStanding;
    /** Standing can be shown (monitored candidate, thresholds set). */
    showsStanding: boolean;
    /** Why no standing is shown, when it is not. */
    unavailableReason: string | null;
    thresholdsHref: string | null;
    warnings: SubjectConcern[];
    hasClass: boolean;
}

/**
 * The most important status first (UI_UX_DESIGN.md §34): the overall
 * standing and one factual warning line per subject that needs attention.
 */
export function AcademicSummary({ label, overall, showsStanding, unavailableReason, thresholdsHref, warnings, hasClass }: AcademicSummaryProps) {
    return (
        <Panel title="Academic Summary">
            {!hasClass ? (
                <p className="text-sm text-ink-muted">Assign the candidate to a class to see their academic standing.</p>
            ) : (
                <div className="grid gap-6 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                    <dl>
                        <dt className="text-sm text-ink-muted">{label}</dt>
                        <dd className="mt-1 font-medium text-ink">
                            {showsStanding ? <OverallStandingValue overall={overall} /> : <span className="font-normal text-ink-muted">Not available</span>}
                        </dd>
                        {unavailableReason !== null && (
                            <dd className="mt-1 text-sm text-ink-muted">
                                {unavailableReason}
                                {thresholdsHref !== null && (
                                    <>
                                        {' '}
                                        <Link href={thresholdsHref} className="font-medium text-primary-700 underline">
                                            Set passing and warning grades
                                        </Link>
                                    </>
                                )}
                            </dd>
                        )}
                    </dl>
                    <section aria-labelledby="current-warnings-heading">
                        <h3 id="current-warnings-heading" className="text-sm text-ink-muted">
                            Current Warnings
                        </h3>
                        {warnings.length === 0 ? (
                            <p className="mt-1 text-sm text-ink">{showsStanding ? 'No current warnings.' : '—'}</p>
                        ) : (
                            <ul className="mt-1 flex flex-col gap-1 text-sm text-ink">
                                {warnings.map((warning) => (
                                    <li key={warning.classSubjectId}>{warningText(warning)}</li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            )}
        </Panel>
    );
}

/** "Subject 2: Failing at 64.20, 2 missing scores", or "Subject 1: 2 missing scores". */
function warningText(warning: SubjectConcern): string {
    const parts: string[] = [];
    if (warning.standing !== null) {
        const grade = warning.grade === null ? '' : formatGrade(warning.grade);
        // "Failing at 64.20", but "Incomplete, 90.00 so far": the grade is not the reason there.
        parts.push(
            grade === '' ? warning.standing.label : warning.standing.value === 'incomplete' ? `${warning.standing.label}, ${grade} so far` : `${warning.standing.label} at ${grade}`,
        );
    }
    if (warning.missingScores > 0) {
        parts.push(`${warning.missingScores} missing ${warning.missingScores === 1 ? 'score' : 'scores'}`);
    }
    if (warning.isProvisional) {
        parts.push('grade in progress');
    }

    return `${warning.subject}: ${parts.join(', ')}`;
}

/**
 * The candidate's result on every finalized assessment, by subject
 * (MILESTONES.md Milestone 6: assessment scores).
 */
export function AssessmentResults({ subjects }: { subjects: SubjectResults[] }) {
    return (
        <Panel title="Assessment Results" description="Finalized assessments only, newest first." bodyClassName="p-0">
            <div className="divide-y divide-line">
                {subjects.map((subject) => (
                    <section key={subject.classSubjectId} aria-labelledby={`results-${subject.classSubjectId}`}>
                        <h3 id={`results-${subject.classSubjectId}`} className="px-5 pt-4 text-sm font-semibold text-ink">
                            {subject.subject.name} <span className="font-normal text-ink-muted">({subject.subject.code})</span>
                        </h3>
                        {subject.assessments.length === 0 ? (
                            <p className="px-5 pb-4 pt-1 text-sm text-ink-muted">No finalized assessments yet.</p>
                        ) : (
                            <Table caption={`Assessment results in ${subject.subject.name}`} className="min-w-[32rem]">
                                <TableHead>
                                    <Th>Assessment</Th>
                                    <Th className="hidden md:table-cell">Category</Th>
                                    <Th>Date</Th>
                                    <Th align="right">Score</Th>
                                    <Th align="right">Percentage</Th>
                                </TableHead>
                                <TableBody>
                                    {subject.assessments.map((result) => (
                                        <Tr key={result.assessment.id}>
                                            <Td className="font-medium text-ink">{result.assessment.title}</Td>
                                            <Td className="hidden text-ink-muted md:table-cell">{result.category}</Td>
                                            <Td className="whitespace-nowrap text-ink">{formatCalendarDate(result.date)}</Td>
                                            <Td align="right" numeric>
                                                <ScoreText score={result.score} maxScore={result.maxScore} />
                                            </Td>
                                            <Td align="right" numeric>
                                                {result.percentage === null ? '—' : formatPercent(result.percentage)}
                                            </Td>
                                        </Tr>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </section>
                ))}
            </div>
        </Panel>
    );
}

function ScoreText({ score, maxScore }: { score: string | null; maxScore: string }) {
    if (score === null) {
        return <StatusBadge tone="neutral">Missing</StatusBadge>;
    }

    return (
        <span className="text-ink">
            {score} <span className="text-ink-muted">/ {maxScore}</span>
        </span>
    );
}

/**
 * The latest finalized results and corrections of finalized scores, newest
 * first. Corrections keep their reason (AGENTS.md §36).
 */
export function RecentActivity({ entries }: { entries: ActivityEntry[] }) {
    const formatDate = useDateFormatter();

    return (
        <Panel title="Recent Academic Activity" description="Latest finalized results and score corrections." bodyClassName={entries.length === 0 ? undefined : 'p-0'}>
            {entries.length === 0 ? (
                <EmptyState
                    icon={History}
                    headingLevel="h3"
                    title="No academic activity yet"
                    description="Results appear here when assessments are finalized, and corrections of finalized scores are listed with their reason."
                />
            ) : (
                <ol className="divide-y divide-line">
                    {entries.map((entry) => (
                        <li key={entry.id} className="flex flex-col gap-1 px-5 py-3 text-sm">
                            {entry.type === 'finalized' ? (
                                <>
                                    <p className="text-ink">
                                        <span className="font-medium">{entry.assessment.title}</span> <span className="text-ink-muted">· {entry.subject}</span>
                                    </p>
                                    <p className="text-ink">
                                        Result finalized: <ScoreText score={entry.score} maxScore={entry.maxScore} />
                                        {entry.percentage !== null && <span className="tabular-nums text-ink-muted"> ({formatPercent(entry.percentage)})</span>}
                                    </p>
                                </>
                            ) : (
                                <>
                                    <p className="flex flex-wrap items-center gap-2 text-ink">
                                        <StatusBadge tone="info">Corrected</StatusBadge>
                                        <span className="font-medium">{entry.assessment.title}</span> <span className="text-ink-muted">· {entry.subject}</span>
                                    </p>
                                    <p className="text-ink">
                                        <span className="sr-only">Score changed from </span>
                                        <span className="tabular-nums">{entry.previousScore ?? 'No score'}</span>
                                        <span aria-hidden="true"> → </span>
                                        <span className="sr-only"> to </span>
                                        <span className="font-semibold tabular-nums">{entry.newScore ?? 'No score'}</span>
                                        <span className="text-ink-muted"> / {entry.maxScore}</span>
                                    </p>
                                    {entry.reason !== null && (
                                        <p className="text-ink">
                                            <span className="text-ink-muted">Reason:</span> {entry.reason}
                                        </p>
                                    )}
                                </>
                            )}
                            <p className="text-xs text-ink-subtle">
                                {entry.type === 'corrected' && `${entry.changedBy} · `}
                                {formatDate.dateTime(entry.at)}
                            </p>
                        </li>
                    ))}
                </ol>
            )}
        </Panel>
    );
}
