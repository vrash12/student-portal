import { Head, router, useForm } from '@inertiajs/react';
import { ListChecks, Users } from 'lucide-react';
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { BuilderSteps } from '@/components/examinations/builder-steps';
import { FocusSummaryCell, type FocusSummary } from '@/components/examinations/focus-events';
import { QuestionMediaList } from '@/components/question-bank/question-media';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { ClientPagination, useClientPagination } from '@/components/ui/client-pagination';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { CheckboxField, FormField, TextInput } from '@/components/ui/form-field';
import { MetricCard } from '@/components/ui/metric-card';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { examinationRoutes } from '@/lib/examination-routes';
import { useDateFormatter } from '@/lib/format';
import type { StaffQuestion } from '@/types/question-bank';

type LifecycleValue = 'draft' | 'published' | 'active' | 'ended' | 'archived';

interface Examination {
    can_post_grades: boolean;
    posted_assessment_id: number | null;
    id: number;
    title: string;
    description: string | null;
    status: string;
    lifecycle: { value: LifecycleValue; label: string; tone: StatusTone };
    duration_minutes: number | null;
    attempt_limit: number;
    passing_score: string | null;
    release_results: boolean;
    opens_at: string | null;
    closes_at: string | null;
    randomize_questions: boolean;
    randomize_choices: boolean;
    question_draw_count: number | null;
    allow_back_navigation: boolean;
    one_question_at_a_time: boolean;
    auto_submit: boolean;
    has_access_code: boolean;
    examination_questions: { id: number; points: string; question: StaffQuestion }[];
}

type ParticipationStatus = 'not_started' | 'active' | 'inactive' | 'submitted' | 'expired';

interface MonitoringRow {
    candidate: { id: number; number: string; name: string };
    status: ParticipationStatus;
    attemptNumber: number | null;
    answered: number;
    questionCount: number;
    lastActivityAt: string | null;
    focus: FocusSummary | null;
}

interface MonitoringTotals {
    candidates: number;
    active: number;
    inactive: number;
    submitted: number;
    expired: number;
    notStarted: number;
    leftScreen: number;
}

interface Monitoring {
    refreshedAt: string;
    totals: MonitoringTotals;
    candidates: MonitoringRow[];
}

const lifecycleDescriptions: Record<LifecycleValue, string> = {
    draft: 'Review delivery settings and questions before publishing.',
    published: 'Monitor participation, grade essays and manage results.',
    active: 'Monitor participation, grade essays and manage results.',
    ended: 'Monitor participation, grade essays and manage results.',
    archived: 'Archived: read-only history of this examination.',
};

const participationStatuses: Record<ParticipationStatus, { label: string; tone: StatusTone }> = {
    not_started: { label: 'Not started', tone: 'neutral' },
    active: { label: 'Active', tone: 'info' },
    inactive: { label: 'Inactive', tone: 'warning' },
    submitted: { label: 'Submitted', tone: 'success' },
    expired: { label: 'Expired', tone: 'danger' },
};

const totalLabels: Record<keyof MonitoringTotals, string> = {
    candidates: 'Candidates',
    active: 'Active',
    inactive: 'Inactive',
    submitted: 'Submitted',
    expired: 'Expired',
    notStarted: 'Not started',
    leftScreen: 'Left screen',
};

const MONITORING_REFRESH_MS = 15000;

export default function ExaminationShow({ examination: exam, monitoring }: { examination: Examination; monitoring: Monitoring }) {
    const [confirm, setConfirm] = useState<'publish' | 'archive' | null>(null);
    const publication = useForm({ confirmation: true });
    const archive = useForm({ reason: '' });

    useEffect(() => {
        if (exam.status !== 'published') {
            return;
        }
        const timer = window.setInterval(() => router.reload({ only: ['monitoring'] }), MONITORING_REFRESH_MS);

        return () => window.clearInterval(timer);
    }, [exam.status]);

    const draft = exam.status === 'draft';
    const archived = exam.status === 'archived';

    return (
        <>
            <Head title={exam.title} />
            <PageHeader
                title={exam.title}
                description={lifecycleDescriptions[exam.lifecycle.value]}
                breadcrumbs={[{ label: 'Examinations', href: examinationRoutes.index() }, { label: exam.title }]}
                actions={
                    <>
                        {draft && (
                            <>
                                <ButtonLink href={examinationRoutes.edit(exam.id)}>Edit Settings</ButtonLink>
                                <ButtonLink href={examinationRoutes.questions.edit(exam.id)}>Choose Questions</ButtonLink>
                            </>
                        )}
                        {!draft && (
                            <>
                                <ButtonLink href={examinationRoutes.grading(exam.id)}>Essay Grading</ButtonLink>
                                <ButtonLink href={examinationRoutes.analysis(exam.id)}>Item Analysis</ButtonLink>
                            </>
                        )}
                        {!draft && exam.can_post_grades && (
                            <ButtonLink href={examinationRoutes.gradebook(exam.id)} variant="primary">
                                {exam.posted_assessment_id ? 'View Posted Grades' : 'Post to Gradebook'}
                            </ButtonLink>
                        )}
                    </>
                }
            />

            {draft && <BuilderSteps current="review" examinationId={exam.id} questionCount={exam.examination_questions.length} />}

            <div className="space-y-6">
                <SummaryTiles exam={exam} />

                {exam.posted_assessment_id && (
                    <Alert tone="info" title="Grades posted to the gradebook">
                        Posted grades are retained even if examination results are hidden later. Regrading an examination does not change the posted assessment;
                        record any gradebook correction separately with a reason.
                    </Alert>
                )}

                <DeliveryReview exam={exam} />

                <QuestionReview exam={exam} draft={draft} publicationErrors={Object.values(publication.errors)} onPublish={() => setConfirm('publish')} />

                {!draft && <Participation monitoring={monitoring} />}

                {!draft && <ResultVisibilityForm exam={exam} />}

                {!archived && (
                    <ArchiveForm
                        reason={archive.data.reason}
                        onReasonChange={(reason) => archive.setData('reason', reason)}
                        errors={archive.errors}
                        onSubmit={() => setConfirm('archive')}
                    />
                )}
            </div>

            <ConfirmDialog
                open={confirm === 'publish'}
                title="Publish this examination?"
                description="Eligible candidates will be able to start during the availability window. Questions and delivery settings will be locked."
                confirmLabel="Publish Examination"
                tone="primary"
                processing={publication.processing}
                onCancel={() => setConfirm(null)}
                onConfirm={() => publication.post(examinationRoutes.publish(exam.id), { onFinish: () => setConfirm(null) })}
            />
            <ConfirmDialog
                open={confirm === 'archive'}
                title="Archive this examination?"
                description="Candidates will no longer be able to start this examination. Existing results and history will be retained."
                confirmLabel="Archive Examination"
                tone="danger"
                processing={archive.processing}
                onCancel={() => setConfirm(null)}
                onConfirm={() => archive.post(examinationRoutes.archive(exam.id), { onFinish: () => setConfirm(null) })}
            />
        </>
    );
}

function SummaryTiles({ exam }: { exam: Examination }) {
    return (
        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <SummaryTile label="Status">
                <StatusBadge tone={exam.lifecycle.tone}>{exam.lifecycle.label}</StatusBadge>
            </SummaryTile>
            <SummaryTile label="Time limit">{exam.duration_minutes ? `${exam.duration_minutes} minutes` : 'Not set'}</SummaryTile>
            <SummaryTile label="Attempts">{exam.attempt_limit}</SummaryTile>
            <SummaryTile label="Passing score">{exam.passing_score !== null ? `${exam.passing_score}%` : 'Not configured'}</SummaryTile>
        </dl>
    );
}

function SummaryTile({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="rounded-lg border border-line bg-surface px-4 py-4">
            <dt className="text-sm font-medium text-ink-muted">{label}</dt>
            <dd className="mt-1 font-semibold text-ink tabular-nums">{children}</dd>
        </div>
    );
}

function DeliveryReview({ exam }: { exam: Examination }) {
    const { dateTime } = useDateFormatter();
    const questionCount = exam.examination_questions.length;
    const drawCount = exam.question_draw_count;
    const drawsSubset = drawCount !== null && drawCount < questionCount;

    return (
        <Panel title="Delivery Review">
            <p className="whitespace-pre-wrap">{exam.description || 'No additional instructions.'}</p>
            <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <Detail label="Opens" value={exam.opens_at ? dateTime(exam.opens_at) : 'Immediately after publication'} />
                <Detail label="Closes" value={exam.closes_at ? dateTime(exam.closes_at) : 'No closing schedule'} />
                <Detail label="Questions per attempt" value={drawsSubset ? `${drawCount} of ${questionCount}, drawn at random for each attempt` : `All ${questionCount}`} />
                <Detail label="Question order" value={exam.randomize_questions ? 'Randomized per attempt' : 'As listed below'} />
                <Detail label="Choices" value={exam.randomize_choices ? 'Randomized' : 'Original order'} />
                <Detail label="Navigation" value={exam.allow_back_navigation ? 'Can revisit questions' : 'Forward only'} />
                <Detail label="Question display" value={exam.one_question_at_a_time ? 'One at a time' : 'Question navigation available'} />
                <Detail label="Time expiry" value={exam.auto_submit ? 'Submit saved answers automatically' : 'Mark attempt expired'} />
                <Detail label="Access code" value={exam.has_access_code ? 'Required to start' : 'Not required'} />
                <Detail label="Candidate results" value={exam.release_results ? 'Released after submission' : 'Hidden from candidates'} />
            </dl>
        </Panel>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-ink-muted">{label}</dt>
            <dd className="font-medium">{value}</dd>
        </div>
    );
}

interface QuestionReviewProps {
    exam: Examination;
    draft: boolean;
    publicationErrors: string[];
    onPublish: () => void;
}

function QuestionReview({ exam, draft, publicationErrors, onPublish }: QuestionReviewProps) {
    const questionCount = exam.examination_questions.length;
    const drawCount = exam.question_draw_count;
    const drawsSubset = drawCount !== null && drawCount < questionCount;
    const unequalPoints = new Set(exam.examination_questions.map((item) => Number(item.points).toFixed(2))).size > 1;
    const subsetProblem =
        drawCount === null
            ? null
            : drawCount > questionCount
              ? `Questions per attempt (${drawCount}) is more than the ${questionCount} selected questions. Add questions or lower the number in Edit Settings.`
              : drawsSubset && unequalPoints
                ? 'Each attempt draws only some of the questions, so every question must be worth the same points. Set equal points in Manage Questions.'
                : null;
    const attemptPoints = drawsSubset
        ? Number(exam.examination_questions[0]?.points ?? 0) * (drawCount ?? 0)
        : exam.examination_questions.reduce((sum, item) => sum + Number(item.points), 0);
    const pointsSummary = drawsSubset ? `${attemptPoints.toFixed(2)} points per attempt (${drawCount} questions)` : `${attemptPoints.toFixed(2)} points`;

    return (
        <Panel
            title="Question Review"
            description={
                <>
                    {questionCount} {questionCount === 1 ? 'item' : 'items'} · {pointsSummary}. Correct answers below are visible only to authorized instructors.
                    {drawsSubset && ' Each candidate receives a different random selection of these questions.'}
                </>
            }
        >
            {draft && subsetProblem && (
                <Alert tone="warning" className="mb-4">
                    {subsetProblem}
                </Alert>
            )}

            {questionCount === 0 ? (
                <EmptyState
                    icon={ListChecks}
                    headingLevel="h3"
                    title="No questions selected"
                    description={draft ? 'Add questions from the Question Bank before publishing.' : 'This examination has no questions.'}
                    action={
                        draft ? (
                            <ButtonLink href={examinationRoutes.questions.edit(exam.id)} variant="primary">
                                Manage Questions
                            </ButtonLink>
                        ) : undefined
                    }
                />
            ) : (
                <ol className="space-y-4">
                    {exam.examination_questions.map((item, index) => (
                        <li key={item.id} className="rounded-lg border border-line p-4">
                            <p className="text-sm text-ink-muted">
                                Question {index + 1} · {item.question.type.label} · {item.points} points
                            </p>
                            <p className="my-2 whitespace-pre-wrap font-medium">{item.question.prompt}</p>
                            <QuestionMediaList className="my-3 space-y-3" media={item.question.media} urlFor={(media) => media.url} />
                            <ul className="space-y-1">
                                {item.question.choices.map((choice) => (
                                    <li key={choice.id} className={choice.isCorrect ? 'font-medium text-success-fg' : ''}>
                                        {choice.label}. {choice.text}
                                        {choice.isCorrect && ' — Correct answer'}
                                        {choice.image && (
                                            <img
                                                src={choice.image.url}
                                                alt={choice.image.description}
                                                loading="lazy"
                                                className="mt-1 block h-auto max-h-40 w-auto max-w-full rounded-md border border-line object-contain"
                                            />
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </li>
                    ))}
                </ol>
            )}

            {publicationErrors.length > 0 && (
                <Alert tone="danger" title="Unable to publish" className="mt-4">
                    <ul className="space-y-1">
                        {publicationErrors.map((error) => (
                            <li key={error}>{error}</li>
                        ))}
                    </ul>
                </Alert>
            )}

            {draft && (
                <div className="mt-5">
                    <Button disabled={!exam.duration_minutes || questionCount === 0 || subsetProblem !== null} onClick={onPublish}>
                        Publish Examination
                    </Button>
                </div>
            )}
        </Panel>
    );
}

function Participation({ monitoring }: { monitoring: Monitoring }) {
    const { dateTime } = useDateFormatter();
    const pagination = useClientPagination(monitoring.candidates);
    const totals = Object.entries(monitoring.totals) as [keyof MonitoringTotals, number][];

    return (
        <Panel
            title="Examination Participation"
            description={`Refreshes every 15 seconds while published · updated ${dateTime(monitoring.refreshedAt)}.`}
            bodyClassName="p-0"
        >
            <div className="space-y-4 p-5">
                <p className="text-sm text-ink-muted">
                    Inactive means no recent server contact; it does not confirm disconnection. Left screen counts candidates who switched tabs or apps or focused
                    another window during the attempt; it is an indicator to follow up on, not proof.
                </p>
                <dl className="grid gap-3 sm:grid-cols-4 lg:grid-cols-7">
                    {totals.map(([key, count]) => (
                        <MetricCard key={key} label={totalLabels[key]} value={count} />
                    ))}
                </dl>
            </div>

            {monitoring.candidates.length === 0 ? (
                <EmptyState
                    icon={Users}
                    headingLevel="h3"
                    title="No eligible candidates"
                    description="No candidates in this class can take this examination."
                />
            ) : (
                <Table caption="Candidate participation" className="min-w-[46rem]">
                    <TableHead>
                        <Th>Candidate</Th>
                        <Th>Status</Th>
                        <Th align="right">Progress</Th>
                        <Th>Left screen</Th>
                        <Th>Last activity</Th>
                    </TableHead>
                    <TableBody>
                        {pagination.rows.map((row) => {
                            const status = participationStatuses[row.status] ?? { label: row.status, tone: 'neutral' as const };

                            return (
                                <Tr key={row.candidate.id}>
                                    <Td>
                                        <p className="font-medium">{row.candidate.name}</p>
                                        <p className="text-xs text-ink-muted">{row.candidate.number}</p>
                                    </Td>
                                    <Td>
                                        <StatusBadge tone={status.tone}>{status.label}</StatusBadge>
                                    </Td>
                                    <Td numeric align="right">
                                        {row.answered} / {row.questionCount}
                                    </Td>
                                    <Td>
                                        <FocusSummaryCell summary={row.focus} />
                                    </Td>
                                    <Td>{dateTime(row.lastActivityAt)}</Td>
                                </Tr>
                            );
                        })}
                    </TableBody>
                </Table>
            )}
            <ClientPagination pagination={pagination} noun={{ one: 'candidate', other: 'candidates' }} label="Participation pages" />
        </Panel>
    );
}

function ResultVisibilityForm({ exam }: { exam: Examination }) {
    const release = useForm({ release_results: exam.release_results, reason: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        release.put(examinationRoutes.results(exam.id), { preserveScroll: true });
    };
    const otherErrors = Object.entries(release.errors)
        .filter(([key]) => key !== 'reason' && key !== 'release_results')
        .map(([, message]) => message);

    return (
        <Panel title="Candidate Results" description="Choose whether candidates can see their results. Every change is recorded with its reason.">
            <form className="space-y-4" onSubmit={submit}>
                <CheckboxField
                    label="Release results to candidates"
                    checked={release.data.release_results}
                    onChange={(event) => release.setData('release_results', event.target.checked)}
                    error={release.errors.release_results}
                />
                <FormField label="Reason for visibility change" required error={release.errors.reason}>
                    <TextInput value={release.data.reason} onChange={(event) => release.setData('reason', event.target.value)} maxLength={500} />
                </FormField>
                {otherErrors.length > 0 && (
                    <Alert tone="danger" title="Unable to save result visibility">
                        {otherErrors.join(' ')}
                    </Alert>
                )}
                <Button type="submit" variant="secondary" loading={release.processing}>
                    Save Result Visibility
                </Button>
            </form>
        </Panel>
    );
}

interface ArchiveFormProps {
    reason: string;
    onReasonChange: (reason: string) => void;
    errors: Partial<Record<string, string>>;
    onSubmit: () => void;
}

function ArchiveForm({ reason, onReasonChange, errors, onSubmit }: ArchiveFormProps) {
    const otherErrors = Object.entries(errors)
        .filter(([key, message]) => key !== 'reason' && message !== undefined)
        .map(([, message]) => message);

    return (
        <Panel
            title="Archive Examination"
            description="Removes this examination from candidate availability and keeps its historical records. All attempts must be finished."
        >
            <form
                className="space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    onSubmit();
                }}
            >
                <FormField label="Reason for archiving" required error={errors.reason}>
                    <TextInput maxLength={500} value={reason} onChange={(event) => onReasonChange(event.target.value)} />
                </FormField>
                {otherErrors.length > 0 && (
                    <Alert tone="danger" title="Unable to archive">
                        {otherErrors.join(' ')}
                    </Alert>
                )}
                <Button type="submit" variant="secondary">
                    Archive Examination
                </Button>
            </form>
        </Panel>
    );
}
