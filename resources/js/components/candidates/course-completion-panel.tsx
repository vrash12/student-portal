import { usePage } from '@inertiajs/react';
import { Award, FileText, TriangleAlert } from 'lucide-react';
import { buttonClasses } from '@/components/ui/button';
import { Panel } from '@/components/ui/panel';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { formatGrade } from '@/lib/format';

/** CourseCompletion::summary, with the two document links. */
export interface CourseCompletionSummary {
    cgpa: { grade: number | null; complete: boolean; gradedSubjects: number; totalSubjects: number };
    finalGrade: { score: number | null; complete: boolean };
    qualification: { status: { value: string; label: string; tone: string }; reasons: string[]; pending: string[] } | null;
    rank: number | null;
    classSize: number;
    /** False: the transcript is printed as PROVISIONAL. */
    transcriptFinal: boolean;
    certificate: { eligible: boolean; reasons: string[] };
    transcriptUrl: string;
    certificateUrl: string;
}

function ordinal(value: number): string {
    const lastTwo = value % 100;
    const suffix = lastTwo >= 11 && lastTwo <= 13 ? 'th' : (['th', 'st', 'nd', 'rd'][value % 10] ?? 'th');

    return `${value}${suffix}`;
}

/**
 * End of the course (owner request, 2026-10-06): the figures printed on the
 * transcript and certificate, and both documents. Administrators only (the
 * class rank is staff only).
 */
export function CourseCompletionPanel({ completion }: { completion: CourseCompletionSummary }) {
    const errors = usePage().props.errors;
    const figures = [
        { label: 'CGPA', value: formatGrade(completion.cgpa.grade), hint: completion.cgpa.complete ? 'Final' : `In progress · ${completion.cgpa.gradedSubjects} of ${completion.cgpa.totalSubjects} subjects` },
        { label: 'Final Course Grade', value: formatGrade(completion.finalGrade.score), hint: completion.finalGrade.complete ? 'Every area graded' : 'Partial' },
        { label: 'Class Rank', value: completion.rank === null ? '—' : ordinal(completion.rank), hint: completion.rank === null ? 'Not ranked' : `of ${completion.classSize}` },
    ];

    return (
        <Panel
            title="Course Completion"
            description="The Transcript of Records and the Certificate of Completion, from the grades, performance areas and class rank above. Every document issued is recorded in the audit history."
            actions={
                <div className="flex flex-wrap gap-2">
                    <a href={completion.transcriptUrl} className={buttonClasses('secondary')}>
                        <FileText className="size-4" aria-hidden="true" />
                        Transcript of Records (PDF)
                    </a>
                    {completion.certificate.eligible ? (
                        <a href={completion.certificateUrl} className={buttonClasses('primary')}>
                            <Award className="size-4" aria-hidden="true" />
                            Certificate of Completion (PDF)
                        </a>
                    ) : (
                        <span className={buttonClasses('secondary', 'md', 'cursor-not-allowed opacity-60')} aria-disabled="true" title="Not available yet: see the reasons below">
                            <Award className="size-4" aria-hidden="true" />
                            Certificate of Completion (PDF)
                        </span>
                    )}
                </div>
            }
        >
            <div className="flex flex-col gap-4">
                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {figures.map((figure) => (
                        <div key={figure.label} className="rounded-lg border border-line-box bg-surface-muted/60 px-4 py-3">
                            <dt className="text-sm text-ink-muted">{figure.label}</dt>
                            <dd className="mt-1 text-2xl font-bold tabular-nums text-ink">{figure.value}</dd>
                            <dd className="text-xs text-ink-muted">{figure.hint}</dd>
                        </div>
                    ))}
                    <div className="rounded-lg border border-line-box bg-surface-muted/60 px-4 py-3">
                        <dt className="text-sm text-ink-muted">Qualification</dt>
                        <dd className="mt-2">
                            {completion.qualification === null ? (
                                <span className="text-ink-muted">Not available</span>
                            ) : (
                                <StatusBadge tone={completion.qualification.status.tone as StatusTone}>{completion.qualification.status.label}</StatusBadge>
                            )}
                        </dd>
                    </div>
                </dl>

                {!completion.transcriptFinal && (
                    <p className="text-sm text-ink-muted">The transcript is printed as PROVISIONAL until the candidate&apos;s status is Completed and every subject and area has a final grade.</p>
                )}

                {!completion.certificate.eligible && (
                    <div className="rounded-lg border border-warning-border bg-warning-bg px-4 py-3 text-sm text-warning-fg">
                        <p className="flex items-center gap-2 font-semibold">
                            <TriangleAlert className="size-4" aria-hidden="true" />
                            The certificate cannot be issued yet
                        </p>
                        <ul className="mt-1 list-disc pl-6">
                            {completion.certificate.reasons.map((reason) => (
                                <li key={reason}>{reason}</li>
                            ))}
                        </ul>
                    </div>
                )}

                {errors.completion && (
                    <p role="alert" className="text-sm text-danger-fg">
                        {errors.completion}
                    </p>
                )}
            </div>
        </Panel>
    );
}
