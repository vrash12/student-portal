import type { InertiaForm } from '@inertiajs/react';
import { useId, type FormEvent, type ReactNode } from 'react';
import { ChoiceEditor } from '@/components/question-bank/choice-editor';
import type { ChoiceDraft, QuestionFormData } from '@/components/question-bank/question-form-data';
import { QuestionPreview } from '@/components/question-bank/question-preview';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { FormField, SelectInput, TextArea, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { RadioCards } from '@/components/ui/radio-cards';
import type { QuestionBankSubject, QuestionLimits, QuestionTypeOption, QuestionTypeValue, StaffQuestion } from '@/types/question-bank';

type SubjectField =
    /** Create: the subjects the user teaches. */
    | { kind: 'select'; options: QuestionBankSubject[] }
    /** Edit: the question's subject, which cannot change. */
    | { kind: 'fixed'; subject: QuestionBankSubject };

interface QuestionFormProps {
    form: InertiaForm<QuestionFormData>;
    subject: SubjectField;
    /** Existing topic names of the subject. */
    topicSuggestions: string[];
    types: QuestionTypeOption[];
    limits: QuestionLimits;
    /** Edit only: the stored question. A locked question's content is shown read-only. */
    stored?: StaffQuestion;
    /** Actions shown with the locked explanation, e.g. Duplicate. */
    lockedActions?: ReactNode;
    submitLabel: string;
    cancelHref: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}

/**
 * The create and edit form of a question (UI_UX_DESIGN.md §36–40, §55),
 * grouped into details, question, answer, and the staff-only explanation.
 * For a locked question the type, text, and choices are read-only; the
 * server enforces this regardless of what the form sends.
 */
export function QuestionForm({
    form,
    subject,
    topicSuggestions,
    types,
    limits,
    stored,
    lockedActions,
    submitLabel,
    cancelHref,
    onSubmit,
}: QuestionFormProps) {
    // Server errors include keys that are not form fields, such as content and choices.2.text.
    const errors = form.errors as Partial<Record<string, string>>;
    const topicListId = useId();
    const locked = stored?.isLocked === true;
    const replacesChoices = stored !== undefined && !locked && stored.type.value !== 'essay' && form.data.type !== stored.type.value;

    const typeOptions = types.map((type) => ({ value: type.value, label: type.label, description: typeDescription(type.value, limits) }));

    const changeType = (value: string) => {
        const type = types.find((option) => option.value === value);
        if (type !== undefined) {
            form.setData('type', type.value);
        }
    };

    const changeChoices = (choices: ChoiceDraft[]) => {
        // Choice errors are keyed by row number, so they no longer match once a row is added or removed.
        if (choices.length !== form.data.choices.length) {
            const choiceKeys = Object.keys(form.errors).filter((key) => key.startsWith('choices.')) as Array<`choices.${number}.text`>;
            if (choiceKeys.length > 0) {
                form.clearErrors(...choiceKeys);
            }
        }

        form.setData('choices', choices);
    };

    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-6">
            {errors.content !== undefined && (
                <Alert tone="danger" title="The question text and answers were not changed">
                    {errors.content}
                </Alert>
            )}

            <FormSection title="Question Details">
                {subject.kind === 'select' ? (
                    <FormField
                        label="Subject"
                        required
                        error={errors.subject_id}
                        hint="Only subjects you teach are listed. The subject cannot be changed after the question is saved."
                    >
                        <SelectInput name="subject_id" value={form.data.subject_id} onChange={(event) => form.setData('subject_id', event.target.value)}>
                            <option value="">Select a subject</option>
                            {subject.options.map((option) => (
                                <option key={option.id} value={String(option.id)}>
                                    {option.name} ({option.code})
                                </option>
                            ))}
                        </SelectInput>
                    </FormField>
                ) : (
                    <div className="flex flex-col gap-1">
                        <p className="text-sm font-medium text-ink">Subject</p>
                        <p className="text-ink">
                            {subject.subject.name} <span className="text-ink-muted">({subject.subject.code})</span>
                        </p>
                        <p className="text-sm text-ink-subtle">The subject of a question cannot be changed.</p>
                    </div>
                )}

                <div className="grid gap-5 sm:grid-cols-3">
                    <FormField
                        label="Topic"
                        error={errors.topic}
                        hint="Optional. Choose a topic of this subject or type a new one."
                        className="sm:col-span-2"
                    >
                        <TextInput
                            name="topic"
                            list={topicListId}
                            value={form.data.topic}
                            onChange={(event) => form.setData('topic', event.target.value)}
                            maxLength={limits.topicLength}
                            autoComplete="off"
                        />
                    </FormField>
                    <FormField label="Points" required error={errors.points} hint="0.01 to 100. The default when added to an examination.">
                        <TextInput
                            name="points"
                            value={form.data.points}
                            onChange={(event) => form.setData('points', event.target.value)}
                            inputMode="decimal"
                            autoComplete="off"
                            className="tabular-nums"
                        />
                    </FormField>
                </div>
                <datalist id={topicListId}>
                    {topicSuggestions.map((name) => (
                        <option key={name} value={name} />
                    ))}
                </datalist>
            </FormSection>

            {locked && stored !== undefined ? (
                <FormSection title="Question Content" description="As candidates will see it, with the correct answer marked for staff.">
                    <Alert tone="info" title="Locked: used in a published examination">
                        <p>
                            This question is part of a published examination, so its text and answers can no longer change. Duplicate it to make a
                            changed version. The topic, points, and explanation can still be edited.
                        </p>
                        {lockedActions !== undefined && <div className="mt-3 flex flex-wrap gap-2">{lockedActions}</div>}
                    </Alert>
                    <QuestionPreview question={stored} />
                </FormSection>
            ) : (
                <>
                    <FormSection title="Question">
                        <RadioCards
                            legend="Question Type"
                            name="type"
                            required
                            options={typeOptions}
                            value={form.data.type}
                            onChange={changeType}
                            error={errors.type}
                        />
                        {replacesChoices && (
                            <p className="text-sm text-ink-muted">Saving with a different type replaces the current answer choices.</p>
                        )}

                        <FormField
                            label="Question"
                            required
                            error={errors.prompt}
                            hint={`Line breaks are kept. Up to ${limits.promptLength.toLocaleString('en-US')} characters.`}
                        >
                            <TextArea
                                name="prompt"
                                value={form.data.prompt}
                                onChange={(event) => form.setData('prompt', event.target.value)}
                                maxLength={limits.promptLength}
                                rows={5}
                            />
                        </FormField>

                        {form.data.type === 'essay' && (
                            <p className="text-sm text-ink-muted">
                                Candidates write their answer, and an instructor grades it. Essay questions have no answer choices; add grading
                                guidance in the explanation.
                            </p>
                        )}
                    </FormSection>

                    {form.data.type === 'multiple_choice' && (
                        <FormSection title="Answer Choices">
                            <ChoiceEditor
                                choices={form.data.choices}
                                limits={limits}
                                error={errors.choices}
                                choiceError={(index) =>
                                    errors[`choices.${index}.text`] ?? errors[`choices.${index}.is_correct`] ?? errors[`choices.${index}`]
                                }
                                onChange={changeChoices}
                            />
                        </FormSection>
                    )}

                    {form.data.type === 'true_false' && (
                        <FormSection title="Correct Answer" description="Candidates choose True or False. They never see which answer is correct.">
                            <RadioCards
                                legend="The statement is"
                                name="correct_answer"
                                required
                                options={[
                                    { value: 'true', label: 'True' },
                                    { value: 'false', label: 'False' },
                                ]}
                                value={form.data.correct_answer}
                                onChange={(value) => form.setData('correct_answer', value === 'true' ? 'true' : 'false')}
                                error={errors.correct_answer}
                            />
                        </FormSection>
                    )}
                </>
            )}

            <FormSection title="Explanation" description="Staff only. Not shown to candidates.">
                <FormField
                    label="Explanation"
                    error={errors.explanation}
                    hint="Optional. Why the answer is correct, or guidance for grading. Not shown to candidates."
                >
                    <TextArea
                        name="explanation"
                        value={form.data.explanation}
                        onChange={(event) => form.setData('explanation', event.target.value)}
                        maxLength={limits.explanationLength}
                        rows={4}
                    />
                </FormField>
            </FormSection>

            {form.hasErrors && (
                <Alert tone="danger" title="The question was not saved">
                    Check the messages next to the fields above.
                </Alert>
            )}

            <FormActions>
                <ButtonLink href={cancelHref} variant="secondary">
                    Cancel
                </ButtonLink>
                <Button type="submit" loading={form.processing}>
                    {submitLabel}
                </Button>
            </FormActions>
        </form>
    );
}

function typeDescription(type: QuestionTypeValue, limits: QuestionLimits): string {
    switch (type) {
        case 'multiple_choice':
            return `${limits.minChoices} to ${limits.maxChoices} choices, one of them correct. Scored automatically.`;
        case 'true_false':
            return 'A statement that is either true or false. Scored automatically.';
        case 'essay':
            return 'A written answer, graded by an instructor.';
    }
}

/**
 * After a failed save, moves focus to the first field with an error so
 * keyboard and screen reader users land on it.
 */
export function focusFirstInvalidField(): void {
    window.requestAnimationFrame(() => {
        document.querySelector<HTMLElement>('form [aria-invalid="true"]')?.focus();
    });
}
