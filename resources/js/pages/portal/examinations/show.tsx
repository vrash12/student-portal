import { Head, router, useForm } from '@inertiajs/react';
import { Clock, ListOrdered, RotateCcw, type LucideIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { FormField, PasswordInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { useDateFormatter } from '@/lib/format';

interface ExamAttempt {
    number: number;
    status: string;
    submittedAt: string | null;
    resultStatus: string | null;
    percentage: string | null;
}

interface Exam {
    id: number;
    title: string;
    description: string | null;
    durationMinutes: number;
    questionCount: number;
    attemptLimit: number;
    attemptsUsed: number;
    requiresCode: boolean;
    available: boolean;
    resumeId: number | null;
    allowBackNavigation: boolean;
    autoSubmit: boolean;
    opensAt: string | null;
    closesAt: string | null;
    releaseResults: boolean;
    attempts: ExamAttempt[];
}

const ATTEMPT_STATUS: Record<string, string> = {
    in_progress: 'In progress',
    submitted: 'Submitted',
    expired: 'Time ended (not submitted)',
};

/**
 * Examination start page (UI_UX_DESIGN.md §88): what the candidate is about
 * to start, the rules that apply, and why starting is not possible when it is not.
 */
export default function ExamStart({ examination: e }: { examination: Exam }) {
    const { dateTime } = useDateFormatter();
    const form = useForm({ access_code: '' });
    // Starting can also fail for the examination as a whole (closed, no attempts left).
    const examinationError = (form.errors as Record<string, string | undefined>).examination;
    const noAttemptsLeft = e.attemptsUsed >= e.attemptLimit;
    const notYetOpen = !e.available && e.opensAt !== null && Date.parse(e.opensAt) > Date.now();
    const unavailableReason = e.resumeId
        ? null
        : noAttemptsLeft
          ? 'You have used all permitted attempts for this examination.'
          : !e.available
            ? notYetOpen
                ? `This examination opens ${dateTime(e.opensAt)}.`
                : 'This examination is closed.'
            : null;

    return (
        <>
            <Head title={e.title} />
            <PageHeader title={e.title} description="Read the instructions and rules before you start." />

            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(`/portal/examinations/${e.id}/start`);
                }}
                className="space-y-6 rounded-xl border border-line bg-surface p-6 shadow-sm"
            >
                {e.description && <p className="whitespace-pre-wrap break-words">{e.description}</p>}

                <dl className="grid gap-3 sm:grid-cols-3">
                    <Fact icon={ListOrdered} label="Questions" value={String(e.questionCount)} />
                    <Fact icon={Clock} label="Time limit" value={`${e.durationMinutes} minutes`} />
                    <Fact icon={RotateCcw} label="Attempts used" value={`${e.attemptsUsed} of ${e.attemptLimit}`} />
                </dl>

                <h2 className="text-base font-semibold">Rules</h2>
                <ul className="-mt-3 list-disc space-y-1.5 pl-5">
                    <li>The timer starts when you begin and keeps running if you leave this page.</li>
                    <li>
                        {e.allowBackNavigation
                            ? 'You may return to earlier questions and flag them for review.'
                            : 'You cannot return to a question after you select Save and Next.'}
                    </li>
                    <li>
                        {e.autoSubmit
                            ? 'When time runs out, your saved answers are submitted automatically.'
                            : 'When time runs out, the attempt ends and your saved answers are kept for your instructor.'}
                    </li>
                    {e.closesAt && <li>The examination closes {dateTime(e.closesAt)}.</li>}
                </ul>

                <p className="rounded-lg border border-info-border bg-info-bg p-3 text-sm text-info-fg">
                    Stay on the examination screen until you submit. Switching to another tab, app, or window during the examination is recorded and visible to your instructor.
                </p>

                {e.requiresCode && !e.resumeId && !unavailableReason && (
                    <FormField label="Access code" hint="Your instructor gives you this code." error={form.errors.access_code}>
                        <PasswordInput autoComplete="off" className="min-h-12" value={form.data.access_code} onChange={(event) => form.setData('access_code', event.target.value)} />
                    </FormField>
                )}

                {examinationError && (
                    <p role="alert" className="rounded-lg border border-danger-border bg-danger-bg p-3 text-danger-fg">
                        {examinationError}
                    </p>
                )}

                {e.resumeId ? (
                    <Button type="button" size="lg" onClick={() => router.visit(`/portal/attempts/${e.resumeId}`)}>
                        Continue Examination
                    </Button>
                ) : unavailableReason ? (
                    <p role="status" className="rounded-lg border border-line bg-canvas p-3 font-medium">
                        {unavailableReason}
                    </p>
                ) : (
                    <Button type="submit" size="lg" loading={form.processing}>
                        Start Examination
                    </Button>
                )}
            </form>

            <section className="rounded-xl border border-line bg-surface p-6 shadow-sm" aria-labelledby="attempt-history">
                <h2 id="attempt-history" className="text-lg font-semibold">
                    Your Attempts
                </h2>
                {e.attempts.length === 0 ? (
                    <p className="mt-3 text-ink-muted">No attempts yet.</p>
                ) : (
                    <ul className="mt-3 space-y-2">
                        {e.attempts.map((attempt) => (
                            <li key={attempt.number} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line p-3 text-sm">
                                <span className="font-medium">Attempt {attempt.number}</span>
                                <span>{ATTEMPT_STATUS[attempt.status] ?? 'Closed'}</span>
                                <span>{attempt.submittedAt ? dateTime(attempt.submittedAt) : ''}</span>
                                <span className="tabular-nums">
                                    {attempt.resultStatus === 'pending_review'
                                        ? 'Awaiting essay review'
                                        : e.releaseResults && attempt.percentage !== null
                                          ? `${attempt.percentage}%`
                                          : 'Result not released'}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
            </div>
        </>
    );
}

function Fact({ icon: Icon, label, value }: { icon: LucideIcon; label: string; value: string }) {
    return (
        <div className="rounded-lg border border-line bg-surface-muted p-4">
            <dt className="flex items-center gap-2 text-sm text-ink-muted">
                <Icon className="size-4" aria-hidden="true" />
                {label}
            </dt>
            <dd className="mt-1 text-xl font-semibold">{value}</dd>
        </div>
    );
}
