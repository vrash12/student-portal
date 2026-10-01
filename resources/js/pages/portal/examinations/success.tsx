import { Head } from '@inertiajs/react';
import { CircleCheck, Clock } from 'lucide-react';
import { useEffect } from 'react';
import { ButtonLink } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { clearRecovery } from '@/lib/exam-recovery';
import { useDateFormatter } from '@/lib/format';

interface ExamSuccessProps {
    title: string;
    status: string;
    submissionKind?: string | null;
    submittedAt?: string | null;
    attemptId?: number;
    releaseResults?: boolean;
    resultStatus?: string | null;
    objectivePoints?: string | null;
    objectiveMaxPoints?: string | null;
    percentage?: string | null;
    passed?: boolean | null;
}

/**
 * The page a candidate sees once an attempt is closed (UI_UX_DESIGN.md §29):
 * what happened, when, and the result only when results are released.
 */
export default function ExamSuccess({ title, status, submissionKind, submittedAt, attemptId, releaseResults, resultStatus, objectivePoints, objectiveMaxPoints, percentage, passed }: ExamSuccessProps) {
    const { dateTime } = useDateFormatter();
    useEffect(() => {
        if (attemptId) void clearRecovery(attemptId);
    }, [attemptId]);

    const submitted = status === 'submitted';
    const automatic = submitted && submissionKind === 'automatic';
    const heading = submitted ? (automatic ? 'Time Ended — Answers Submitted' : 'Examination Submitted') : status === 'expired' ? 'Time Ended' : 'Attempt Closed';
    const description = submitted
        ? automatic
            ? `The time limit ended, so your saved answers to ${title} were submitted automatically.`
            : `Your responses to ${title} were received.`
        : status === 'expired'
          ? `The time limit for ${title} ended. Your saved answers were kept for your instructor; this attempt was not submitted.`
          : `This attempt of ${title} is closed. Contact your instructor if you have questions.`;
    const Icon = submitted && !automatic ? CircleCheck : Clock;

    return (
        <>
            <Head title={heading} />
            <PageHeader title={heading} description={description} />

            <section className="mb-6 max-w-xl rounded-xl border border-line bg-surface p-6 shadow-sm">
                <p className="flex items-center gap-3 text-lg font-semibold">
                    <span className={'flex size-12 items-center justify-center rounded-full ' + (submitted ? 'bg-success-bg text-success-fg' : 'bg-warning-bg text-warning-fg')}>
                        <Icon className="size-7" aria-hidden="true" />
                    </span>
                    {submitted ? 'Submission received' : status === 'expired' ? 'Not submitted' : 'Closed'}
                </p>
                {submittedAt && <p className="mt-2 text-sm text-ink-muted">Submitted: {dateTime(submittedAt)}</p>}

                {submitted && releaseResults && (
                    <div className="mt-4 border-t border-line pt-4">
                        <h2 className="text-lg font-semibold">Result</h2>
                        {resultStatus === 'pending_review' ? (
                            <p className="mt-2">
                                Objective items: {objectivePoints ?? '0'} / {objectiveMaxPoints ?? '0'}. Essay responses are waiting for instructor review, so the final score is pending.
                            </p>
                        ) : (
                            <p className="mt-2">
                                Score: {percentage ?? '—'}%{passed === true ? ' · Passed' : passed === false ? ' · Not passed' : ''}
                            </p>
                        )}
                    </div>
                )}
                {submitted && !releaseResults && <p className="mt-2 text-sm text-ink-muted">Results will be shown when your instructor releases them.</p>}
            </section>

            <ButtonLink href="/portal" size="lg">
                Return to My Home
            </ButtonLink>
        </>
    );
}
