import { Head } from '@inertiajs/react';
import { CircleCheck, CircleMinus, Copy, Pencil } from 'lucide-react';
import type { ReactNode } from 'react';
import { PostActionButton } from '@/components/question-bank/post-action-button';
import { pointsLabel } from '@/components/question-bank/question-form-data';
import { QuestionPreview } from '@/components/question-bank/question-preview';
import { LockedIndicator, QuestionStatusBadge } from '@/components/question-bank/question-status';
import { Alert } from '@/components/ui/alert';
import { ButtonLink } from '@/components/ui/button';
import { ConfirmAction } from '@/components/ui/confirm-action';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { useDateFormatter } from '@/lib/format';
import { examinationRoutes } from '@/lib/examination-routes';
import { routes } from '@/lib/routes';
import type { StaffQuestion } from '@/types/question-bank';

interface ShowQuestionProps {
    question: StaffQuestion;
    record: {
        /** When the question was first included in a published examination. */
        lockedAt: string | null;
        createdBy: string;
        createdAt: string | null;
        /** The last content edit; null when the question has not been edited. */
        updatedBy: string | null;
        updatedAt: string | null;
    };
}

export default function ShowQuestion({ question, record }: ShowQuestionProps) {
    const formatDate = useDateFormatter();

    return (
        <>
            <Head title={`Question Preview · ${question.subject.name}`} />

            <PageHeader
                title="Question Preview"
                description={`${question.subject.name} · ${question.topic === null ? 'No topic' : question.topic.name}`}
                breadcrumbs={[
                    { label: 'Examinations', href: examinationRoutes.index() },
                        { label: 'Question Bank', href: routes.questionBank.index() },
                    { label: question.subject.name, href: routes.questionBank.index({ subject: String(question.subject.id) }) },
                    { label: 'Preview' },
                ]}
                actions={
                    <>
                        <ButtonLink href={routes.questionBank.edit(question.id)} variant="primary" icon={<Pencil className="size-4" aria-hidden="true" />}>
                            Edit Question
                        </ButtonLink>
                        <PostActionButton href={routes.questionBank.duplicate(question.id)} icon={<Copy className="size-4" aria-hidden="true" />}>
                            Duplicate
                        </PostActionButton>
                        {question.isActive ? (
                            <ConfirmAction
                                href={routes.questionBank.deactivate(question.id)}
                                method="post"
                                variant="secondary"
                                size="md"
                                tone="primary"
                                icon={<CircleMinus className="size-4" aria-hidden="true" />}
                                title="Deactivate this question?"
                                description={
                                    <>
                                        <p>It can no longer be added to examinations. Examinations that already include it keep it.</p>
                                        <p>The question stays in the question bank, and you can activate it again at any time.</p>
                                    </>
                                }
                                confirmLabel="Deactivate Question"
                            >
                                Deactivate
                            </ConfirmAction>
                        ) : (
                            <PostActionButton href={routes.questionBank.activate(question.id)} icon={<CircleCheck className="size-4" aria-hidden="true" />}>
                                Activate
                            </PostActionButton>
                        )}
                    </>
                }
            />

            <div className="flex flex-col gap-6">
                {question.isLocked && (
                    <Alert tone="info" title="Locked: used in a published examination">
                        Since {formatDate.dateTime(record.lockedAt)}, this question is part of a published examination, so its type, text, and
                        answers can no longer change. Duplicate it to make a changed version. The topic, points, and explanation can still be
                        edited.
                    </Alert>
                )}
                {!question.isActive && (
                    <Alert tone="info" title="Inactive">
                        This question cannot be added to examinations. Examinations that already include it keep it. Activate it to use it
                        again.
                    </Alert>
                )}

                <div className="grid gap-6 lg:grid-cols-3">
                    <Panel title="Question" description="As candidates will see it, with the correct answer marked for staff." className="lg:col-span-2">
                        <QuestionPreview question={question} />
                    </Panel>

                    <Panel title="Details">
                        <dl className="flex flex-col gap-4">
                            <Detail label="Subject">
                                {question.subject.name} <span className="font-normal text-ink-muted">({question.subject.code})</span>
                            </Detail>
                            <Detail label="Topic">{question.topic === null ? <span className="font-normal text-ink-muted">No topic</span> : question.topic.name}</Detail>
                            <Detail label="Type">{question.type.label}</Detail>
                            <Detail label="Points">
                                <span className="tabular-nums">{pointsLabel(question.points)}</span>
                            </Detail>
                            <Detail label="Status">
                                <span className="flex flex-wrap gap-1.5">
                                    <QuestionStatusBadge isActive={question.isActive} />
                                    {question.isLocked && <LockedIndicator />}
                                </span>
                            </Detail>
                            <Detail label="Locked Since">
                                {record.lockedAt === null ? (
                                    <span className="font-normal text-ink-muted">Not used in a published examination</span>
                                ) : (
                                    formatDate.dateTime(record.lockedAt)
                                )}
                            </Detail>
                            <Detail label="Created">
                                {record.createdBy}
                                <span className="block font-normal text-ink-muted">{formatDate.dateTime(record.createdAt)}</span>
                            </Detail>
                            <Detail label="Last Updated">
                                {record.updatedBy === null ? (
                                    <span className="font-normal text-ink-muted">Not changed since it was created</span>
                                ) : (
                                    <>
                                        {record.updatedBy}
                                        <span className="block font-normal text-ink-muted">{formatDate.dateTime(record.updatedAt)}</span>
                                    </>
                                )}
                            </Detail>
                        </dl>
                    </Panel>
                </div>

                <Panel title="Explanation" description="Staff only. Not shown to candidates.">
                    {question.explanation === null ? (
                        <p className="text-sm text-ink-muted">No explanation.</p>
                    ) : (
                        <p className="whitespace-pre-wrap break-words text-ink">{question.explanation}</p>
                    )}
                </Panel>
            </div>
        </>
    );
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <dt className="text-sm text-ink-muted">{label}</dt>
            <dd className="mt-0.5 font-medium text-ink">{children}</dd>
        </div>
    );
}
