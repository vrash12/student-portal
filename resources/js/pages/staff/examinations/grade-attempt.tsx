import { Head, useForm } from '@inertiajs/react';
import { FocusEventList, type FocusEvent } from '@/components/examinations/focus-events';
import { QuestionMediaList } from '@/components/question-bank/question-media';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { CheckboxField, FormField, TextArea, TextInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { Panel } from '@/components/ui/panel';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { examinationRoutes } from '@/lib/examination-routes';
import { useDateFormatter } from '@/lib/format';
import type { Paginated } from '@/types';
import type { QuestionMediaView } from '@/types/question-bank';

interface Essay {
    id: number;
    /** Position in the attempt as the candidate saw it (1-based). */
    number: number | null;
    prompt: string;
    media: QuestionMediaView[];
    answer: string;
    maxPoints: string;
    grade: string | null;
    comment: string | null;
    version: number;
    grader: string | null;
    gradedAt: string | null;
}

interface Revision {
    id: number;
    itemId: number;
    /** Question number in the attempt's delivered order (1-based). */
    itemNumber: number | null;
    actor: string;
    at: string;
    before: string | null;
    after: string;
    reason: string | null;
    commentBefore: string | null;
    commentAfter: string | null;
}

interface Attempt {
    id: number;
    number: number;
    candidate: string;
    candidateNumber: string;
    status: string;
    percentage: string | null;
}

interface GradeAttemptProps {
    examination: { id: number; title: string };
    attempt: Attempt;
    questions: Essay[];
    history: Paginated<Revision>;
    nextAttemptId: number | null;
    focusEvents: FocusEvent[];
}

/** Fields shown next to their own control; any other error goes in the form's Alert. */
const ESSAY_FIELD_ERRORS = new Set(['score', 'comment', 'reason', 'next']);

export default function GradeAttempt({ examination, attempt, questions, history, nextAttemptId, focusEvents }: GradeAttemptProps) {
    const { dateTime } = useDateFormatter();
    const pending = attempt.status === 'pending_review';

    return (
        <>
            <Head title={`Grade Attempt · ${attempt.candidate}`} />
            <PageHeader
                title={`Attempt ${attempt.number}`}
                description={`${attempt.candidate} · ${attempt.candidateNumber} · ${examination.title}`}
                breadcrumbs={[
                    { label: 'Examinations', href: examinationRoutes.index() },
                    { label: examination.title, href: examinationRoutes.show(examination.id) },
                    { label: 'Essay Grading', href: examinationRoutes.grading(examination.id) },
                    { label: `Attempt ${attempt.number}` },
                ]}
                actions={
                    <>
                        <ButtonLink href={examinationRoutes.grading(examination.id)}>Back to Queue</ButtonLink>
                        {nextAttemptId && (
                            <ButtonLink href={examinationRoutes.gradeAttempt(nextAttemptId)} variant="primary">
                                Next Pending Attempt
                            </ButtonLink>
                        )}
                    </>
                }
            />

            <div className="mb-6 rounded-xl border border-line-box bg-surface p-5">
                <p className="text-sm text-ink-muted">Current result</p>
                <div className="mt-2 flex flex-wrap items-center gap-3">
                    <StatusBadge tone={pending ? 'warning' : 'success'}>{pending ? 'Pending essay review' : 'Final'}</StatusBadge>
                    {!pending && <span className="text-xl font-semibold tabular-nums">{attempt.percentage !== null ? `${attempt.percentage}%` : '—'}</span>}
                </div>
            </div>

            <div className="space-y-6">
                {questions.map((question) => (
                    <EssayForm key={`${attempt.id}-${question.id}-${question.version}`} attemptId={attempt.id} question={question} />
                ))}
            </div>

            <section className="mt-8" aria-labelledby="grading-history">
                <h2 id="grading-history" className="text-lg font-semibold">
                    Grading History
                </h2>
                <div className="mt-3 rounded-xl border border-line-box bg-surface">
                    {history.data.length === 0 ? (
                        <p className="p-5 text-ink-muted">No scores have been saved.</p>
                    ) : (
                        <Table caption="Essay score changes for this attempt" className="min-w-[42rem]">
                            <TableHead>
                                <Th>Time</Th>
                                <Th>Item</Th>
                                <Th>Change</Th>
                                <Th>Reason</Th>
                            </TableHead>
                            <TableBody>
                                {history.data.map((revision) => (
                                    <Tr key={revision.id}>
                                        <Td>
                                            {dateTime(revision.at)}
                                            <p className="text-ink-muted">{revision.actor}</p>
                                        </Td>
                                        <Td>{revision.itemNumber !== null ? `Question ${revision.itemNumber}` : 'Question'}</Td>
                                        <Td>
                                            <span className="tabular-nums">
                                                {revision.before ?? '—'} → {revision.after}
                                            </span>
                                            {revision.commentBefore !== revision.commentAfter && (
                                                <details className="mt-2">
                                                    <summary className="cursor-pointer">Feedback change</summary>
                                                    <p className="mt-2 whitespace-pre-wrap">Previous: {revision.commentBefore || 'None'}</p>
                                                    <p className="mt-2 whitespace-pre-wrap">New: {revision.commentAfter || 'None'}</p>
                                                </details>
                                            )}
                                        </Td>
                                        <Td>{revision.reason ?? 'Initial score'}</Td>
                                    </Tr>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                    <Pagination page={history} noun={{ one: 'revision', other: 'revisions' }} />
                </div>
            </section>

            <FocusEventList events={focusEvents} />
        </>
    );
}

function EssayForm({ attemptId, question }: { attemptId: number; question: Essay }) {
    const { dateTime } = useDateFormatter();
    const form = useForm({
        item_id: question.id,
        score: question.grade ?? '',
        version: question.version,
        comment: question.comment ?? '',
        reason: '',
        next: false,
    });
    const correction = question.grade !== null;
    const otherErrors = Object.entries(form.errors)
        .filter(([key, message]) => !ESSAY_FIELD_ERRORS.has(key) && message !== undefined)
        .map(([, message]) => message);
    const heading = question.number !== null ? `Question ${question.number}` : 'Essay question';

    return (
        <Panel title={heading} description={`Essay · maximum ${question.maxPoints} points`}>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(examinationRoutes.saveEssay(attemptId), { preserveScroll: true });
                }}
            >
                <p className="whitespace-pre-wrap font-medium">{question.prompt}</p>
                <QuestionMediaList className="mt-3 space-y-3" media={question.media} urlFor={(media) => `/question-media/${media.id}`} />
                <p className="mt-3 text-sm font-medium text-ink-muted">Candidate response</p>
                <p className="mt-1 whitespace-pre-wrap rounded-lg bg-surface-muted p-4 text-sm">{question.answer || 'No answer submitted.'}</p>

                <div className="mt-4 grid gap-4 sm:grid-cols-[12rem_1fr]">
                    <FormField label={`Score (out of ${question.maxPoints})`} required error={form.errors.score}>
                        <TextInput
                            type="number"
                            inputMode="decimal"
                            min="0"
                            max={question.maxPoints}
                            step="0.01"
                            value={form.data.score}
                            onChange={(event) => form.setData('score', event.target.value)}
                            required
                        />
                    </FormField>
                    <FormField label="Instructor comment" error={form.errors.comment}>
                        <TextArea rows={3} maxLength={5000} value={form.data.comment} onChange={(event) => form.setData('comment', event.target.value)} />
                    </FormField>
                </div>

                {correction && (
                    <FormField
                        label="Reason for correction"
                        required
                        hint="Required because this essay already has a recorded score."
                        error={form.errors.reason}
                        className="mt-4"
                    >
                        <TextArea rows={3} maxLength={1000} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} required />
                    </FormField>
                )}

                {otherErrors.length > 0 && (
                    <Alert tone="danger" title="Unable to save the score" className="mt-4">
                        <ul className="space-y-1">
                            {otherErrors.map((message) => (
                                <li key={message}>{message}</li>
                            ))}
                        </ul>
                    </Alert>
                )}

                <div className="mt-4 flex flex-wrap items-center gap-x-6 gap-y-3">
                    <Button type="submit" loading={form.processing}>
                        Save Score
                    </Button>
                    <CheckboxField
                        label="Open next pending attempt"
                        checked={form.data.next}
                        onChange={(event) => form.setData('next', event.target.checked)}
                        error={form.errors.next}
                    />
                    {question.gradedAt && (
                        <span className="text-sm text-ink-muted">
                            Saved by {question.grader} on {dateTime(question.gradedAt)}
                        </span>
                    )}
                </div>
            </form>
        </Panel>
    );
}
