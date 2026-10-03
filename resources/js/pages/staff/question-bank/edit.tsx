import { Head, useForm } from '@inertiajs/react';
import { Copy } from 'lucide-react';
import type { FormEvent } from 'react';
import { PostActionButton } from '@/components/question-bank/post-action-button';
import { QuestionMediaManager } from '@/components/question-bank/question-media-manager';
import { focusFirstInvalidField, QuestionForm } from '@/components/question-bank/question-form';
import { questionFormDataFrom, questionPayload, type QuestionFormData } from '@/components/question-bank/question-form-data';
import { useUnsavedChangesWarning } from '@/components/question-bank/use-unsaved-changes-warning';
import { PageHeader } from '@/components/ui/page-header';
import { examinationRoutes } from '@/lib/examination-routes';
import { routes } from '@/lib/routes';
import type { QuestionLimits, QuestionTypeOption, StaffQuestion } from '@/types/question-bank';

interface EditQuestionProps {
    question: StaffQuestion;
    /** Existing topic names of the question's subject. */
    topics: string[];
    types: QuestionTypeOption[];
    limits: QuestionLimits;
}

export default function EditQuestion({ question, topics, types, limits }: EditQuestionProps) {
    const form = useForm<QuestionFormData>(() => questionFormDataFrom(question));
    const updatePath = routes.questionBank.update(question.id);

    useUnsavedChangesWarning(form.isDirty && !form.processing, updatePath);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (form.processing) {
            return;
        }

        // A locked question's content cannot change, so only its details are sent.
        form.transform((data) => questionPayload(data, { includeSubject: false, includeContent: !question.isLocked }));
        form.put(updatePath, { preserveScroll: true, onError: focusFirstInvalidField });
    };

    return (
        <>
            <Head title={`Edit Question · ${question.subject.name}`} />

            <div className="mx-auto max-w-6xl">
                <PageHeader
                    title="Edit Question"
                    description={`${question.subject.name} · ${question.type.label}`}
                    breadcrumbs={[
                        { label: 'Examinations', href: examinationRoutes.index() },
                        { label: 'Question Bank', href: routes.questionBank.index() },
                        { label: question.subject.name, href: routes.questionBank.index({ subject: String(question.subject.id) }) },
                        { label: 'Preview', href: routes.questionBank.show(question.id) },
                        { label: 'Edit' },
                    ]}
                />

                <QuestionForm
                    form={form}
                    subject={{ kind: 'fixed', subject: question.subject }}
                    topicSuggestions={topics}
                    types={types}
                    limits={limits}
                    stored={question}
                    lockedActions={
                        <PostActionButton
                            href={routes.questionBank.duplicate(question.id)}
                            icon={<Copy className="size-4" aria-hidden="true" />}
                            preserveState={false}
                        >
                            Duplicate Question
                        </PostActionButton>
                    }
                    submitLabel="Save Changes"
                    cancelHref={routes.questionBank.show(question.id)}
                    onSubmit={submit}
                />

                {/* Aligned with the form column, beside the preview on wide screens. */}
                <div className={question.isLocked ? '' : 'lg:mr-[22.5rem]'}>
                    <QuestionMediaManager question={question} />
                </div>
            </div>
        </>
    );
}
