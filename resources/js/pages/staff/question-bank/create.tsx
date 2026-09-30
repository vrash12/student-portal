import { Head, useForm } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';
import type { FormEvent } from 'react';
import { focusFirstInvalidField, QuestionForm } from '@/components/question-bank/question-form';
import { newQuestionFormData, questionPayload, type QuestionFormData } from '@/components/question-bank/question-form-data';
import { useUnsavedChangesWarning } from '@/components/question-bank/use-unsaved-changes-warning';
import { ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';
import type { QuestionBankSubject, QuestionLimits, QuestionTypeOption } from '@/types/question-bank';

interface CreateQuestionProps {
    /** The subjects the user teaches, with their existing topic names. */
    subjects: Array<QuestionBankSubject & { topics: string[] }>;
    /** From ?subject=, or the only subject the user teaches. */
    selectedSubjectId: number | null;
    types: QuestionTypeOption[];
    limits: QuestionLimits;
}

export default function CreateQuestion({ subjects, selectedSubjectId, types, limits }: CreateQuestionProps) {
    const form = useForm<QuestionFormData>(() => newQuestionFormData(selectedSubjectId));
    const selected = subjects.find((subject) => String(subject.id) === form.data.subject_id) ?? null;
    const cancelHref = routes.questionBank.index(selectedSubjectId === null ? {} : { subject: String(selectedSubjectId) });

    useUnsavedChangesWarning(form.isDirty && !form.processing, routes.questionBank.store());

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (form.processing) {
            return;
        }

        form.transform((data) => questionPayload(data, { includeSubject: true, includeContent: true }));
        form.post(routes.questionBank.store(), { preserveScroll: true, onError: focusFirstInvalidField });
    };

    return (
        <>
            <Head title="Add Question" />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title="Add Question"
                    description="Questions can be reused in quizzes and examinations of the same subject."
                    breadcrumbs={[{ label: 'Question Bank', href: routes.questionBank.index() }, { label: 'Add Question' }]}
                />

                {subjects.length === 0 ? (
                    <div className="rounded-lg border border-line bg-surface">
                        <EmptyState
                            icon={ClipboardList}
                            title="You do not teach any subjects yet"
                            description="Questions belong to a subject you teach. Once an academic administrator assigns you to teach a subject of a class, you can add questions for it."
                            action={<ButtonLink href={routes.questionBank.index()}>Back to Question Bank</ButtonLink>}
                        />
                    </div>
                ) : (
                    <QuestionForm
                        form={form}
                        subject={{ kind: 'select', options: subjects }}
                        topicSuggestions={selected?.topics ?? []}
                        types={types}
                        limits={limits}
                        submitLabel="Save Question"
                        cancelHref={cancelHref}
                        onSubmit={submit}
                    />
                )}
            </div>
        </>
    );
}
