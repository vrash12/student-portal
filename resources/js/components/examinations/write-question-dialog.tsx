import { useForm } from '@inertiajs/react';
import { useRef, type FormEvent } from 'react';
import { focusFirstInvalidField, QuestionForm } from '@/components/question-bank/question-form';
import { newQuestionFormData, questionPayload, type QuestionFormData } from '@/components/question-bank/question-form-data';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { examinationRoutes } from '@/lib/examination-routes';
import type { QuestionBankSubject, QuestionLimits, QuestionTypeOption, StaffQuestion } from '@/types/question-bank';

interface WriteQuestionDialogProps {
    open: boolean;
    examinationId: number;
    subject: QuestionBankSubject;
    topics: string[];
    types: QuestionTypeOption[];
    limits: QuestionLimits;
    /** After each save: the subject's saved questions, now including the new one. */
    onSaved: (savedQuestions: StaffQuestion[]) => void;
    onClose: () => void;
}

/**
 * Write a New Question from an examination's Questions step (owner request,
 * 2026-10-03): the usual question form, with the subject fixed to the
 * examination's. The server saves it to the question bank and adds it as the
 * examination's last question. The draft stays here while the dialog is
 * closed, until it is saved.
 */
export function WriteQuestionDialog({ open, examinationId, subject, topics, types, limits, onSaved, onClose }: WriteQuestionDialogProps) {
    const form = useForm<QuestionFormData>(() => newQuestionFormData(subject.id));
    // Set by "Save and Write Another": the dialog then stays open with an empty form.
    const writeAnother = useRef(false);
    const errors = form.errors as Partial<Record<string, string>>;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (form.processing) {
            return;
        }

        const another = writeAnother.current;
        writeAnother.current = false;
        form.transform((data) => questionPayload(data, { includeSubject: false, includeContent: true }));
        form.post(examinationRoutes.questions.store(examinationId), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                form.reset();
                form.clearErrors();
                onSaved((page.props as unknown as { questions?: StaffQuestion[] }).questions ?? []);
                if (!another) {
                    onClose();
                }
            },
            onError: focusFirstInvalidField,
        });
    };

    return (
        <Dialog
            open={open}
            size="xl"
            title="Write a New Question"
            description={`For ${subject.name}. It is added to this examination and kept in the Question Bank, so it can be reused later. Images, audio, or video can be added from the Question Bank after saving.`}
            busy={form.processing}
            onClose={onClose}
        >
            {errors.examination !== undefined && (
                <Alert tone="danger" title="The question was not added" className="mb-5">
                    {errors.examination}
                </Alert>
            )}
            <QuestionForm
                form={form}
                subject={{ kind: 'fixed', subject }}
                topicSuggestions={topics}
                types={types}
                limits={limits}
                submitLabel="Save and Add"
                extraActions={
                    <Button type="submit" variant="secondary" disabled={form.processing} onClick={() => (writeAnother.current = true)}>
                        Save and Write Another
                    </Button>
                }
                onCancel={onClose}
                onSubmit={submit}
            />
        </Dialog>
    );
}
