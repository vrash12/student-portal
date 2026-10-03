import type { InertiaForm } from '@inertiajs/react';
import { CircleCheck, Eye, ListChecks, PenLine, ToggleLeft } from 'lucide-react';
import { useId, type FormEvent, type ReactNode } from 'react';
import { ChoiceEditor, choiceLetter } from '@/components/question-bank/choice-editor';
import type { ChoiceDraft, QuestionFormData } from '@/components/question-bank/question-form-data';
import { QuestionPreview } from '@/components/question-bank/question-preview';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { FormField, SelectInput, TextArea, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { RadioCards } from '@/components/ui/radio-cards';
import { cn } from '@/lib/cn';
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
    /** More submit buttons next to the main one, e.g. "Save and Add Another". */
    extraActions?: ReactNode;
    /** Where Cancel leads on a page. */
    cancelHref?: string;
    /** Cancel in a dialog (instead of cancelHref). */
    onCancel?: () => void;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}

const TYPE_ICONS = { multiple_choice: ListChecks, true_false: ToggleLeft, essay: PenLine } as const;

/**
 * The create and edit form of a question (UI_UX_DESIGN.md §36–40, §55), as
 * numbered steps in the order an instructor thinks: what kind of question,
 * the question, its answer, then scoring and filing. A live preview shows the
 * question as candidates will see it. For a locked question the type, text,
 * and choices are read-only; the server enforces this regardless.
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
    extraActions,
    cancelHref,
    onCancel,
    onSubmit,
}: QuestionFormProps) {
    // Server errors include keys that are not form fields, such as content and choices.2.text.
    const errors = form.errors as Partial<Record<string, string>>;
    const topicListId = useId();
    const locked = stored?.isLocked === true;
    const replacesChoices = stored !== undefined && !locked && stored.type.value !== 'essay' && form.data.type !== stored.type.value;

    const typeOptions = types.map((type) => ({ value: type.value, label: type.label, description: typeDescription(type.value, limits), icon: TYPE_ICONS[type.value] }));

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

    const subjectField =
        subject.kind === 'select' ? (
            <FormField label="Subject" required error={errors.subject_id} hint="Only subjects you teach are listed. It cannot be changed after saving.">
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
        );

    return (
        <form onSubmit={onSubmit} noValidate className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_21rem] lg:items-start">
            <div className="flex min-w-0 flex-col gap-6">
                {errors.content !== undefined && (
                    <Alert tone="danger" title="The question text and answers were not changed">
                        {errors.content}
                    </Alert>
                )}

                {locked && stored !== undefined ? (
                    <FormSection step={1} title="Question Content" description="As candidates will see it, with the correct answer marked for staff.">
                        {subjectField}
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
                        <FormSection step={1} title="Subject and Question Type" description="Choose what kind of question this is.">
                            {subjectField}
                            <RadioCards legend="Question type" name="type" required columns={3} options={typeOptions} value={form.data.type} onChange={changeType} error={errors.type} />
                            {replacesChoices && <p className="text-sm text-warning-fg">Saving with a different type replaces the current answer choices.</p>}
                        </FormSection>

                        <FormSection step={2} title="Question" description="Write the question exactly as candidates should read it.">
                            <FormField
                                label="Question text"
                                required
                                error={errors.prompt}
                                hint={`Line breaks are kept. ${form.data.prompt.length.toLocaleString('en-US')} of ${limits.promptLength.toLocaleString('en-US')} characters.`}
                            >
                                <TextArea
                                    name="prompt"
                                    value={form.data.prompt}
                                    onChange={(event) => form.setData('prompt', event.target.value)}
                                    maxLength={limits.promptLength}
                                    rows={5}
                                    placeholder={form.data.type === 'true_false' ? 'Write a statement that is either true or false.' : form.data.type === 'essay' ? 'Write the essay question or task.' : 'Write the question.'}
                                    className="text-base"
                                />
                            </FormField>
                            <p className="text-sm text-ink-muted">Images, audio and video are added after saving, from the question's Edit page.</p>
                        </FormSection>

                        {form.data.type === 'multiple_choice' && (
                            <FormSection step={3} title="Answer Choices">
                                <ChoiceEditor
                                    choices={form.data.choices}
                                    limits={limits}
                                    error={errors.choices}
                                    choiceError={(index) => errors[`choices.${index}.text`] ?? errors[`choices.${index}.is_correct`] ?? errors[`choices.${index}`]}
                                    onChange={changeChoices}
                                />
                            </FormSection>
                        )}

                        {form.data.type === 'true_false' && (
                            <FormSection step={3} title="Correct Answer" description="Candidates choose True or False. They never see which answer is correct.">
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

                        {form.data.type === 'essay' && (
                            <FormSection step={3} title="Answer" description="Essay answers are graded by an instructor.">
                                <p className="text-sm text-ink-muted">
                                    Candidates type their answer. There are no answer choices; put grading guidance in the explanation (step 5) so every grader
                                    marks the same way.
                                </p>
                            </FormSection>
                        )}
                    </>
                )}

                <FormSection step={locked ? 2 : 4} title="Points and Topic">
                    <div className="grid gap-5 sm:grid-cols-3">
                        <FormField label="Points" required error={errors.points} hint="0.01 to 100. Used when added to an examination.">
                            <TextInput
                                name="points"
                                value={form.data.points}
                                onChange={(event) => form.setData('points', event.target.value)}
                                inputMode="decimal"
                                autoComplete="off"
                                className="tabular-nums"
                            />
                        </FormField>
                        <FormField label="Topic" error={errors.topic} hint="Optional. Pick an existing topic or type a new one." className="sm:col-span-2">
                            <TextInput
                                name="topic"
                                list={topicListId}
                                value={form.data.topic}
                                onChange={(event) => form.setData('topic', event.target.value)}
                                maxLength={limits.topicLength}
                                autoComplete="off"
                            />
                        </FormField>
                    </div>
                    <datalist id={topicListId}>
                        {topicSuggestions.map((name) => (
                            <option key={name} value={name} />
                        ))}
                    </datalist>
                </FormSection>

                <FormSection step={locked ? 3 : 5} title="Explanation (optional)" description="Staff only. Never shown to candidates.">
                    <FormField label="Explanation" error={errors.explanation} hint="Why the answer is correct, or how to grade an essay.">
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
                    {onCancel !== undefined ? (
                        <Button type="button" variant="secondary" onClick={onCancel}>
                            Cancel
                        </Button>
                    ) : (
                        cancelHref !== undefined && (
                            <ButtonLink href={cancelHref} variant="secondary">
                                Cancel
                            </ButtonLink>
                        )
                    )}
                    {extraActions}
                    <Button type="submit" loading={form.processing}>
                        {submitLabel}
                    </Button>
                </FormActions>
            </div>

            {!locked && (
                <aside className="lg:sticky lg:top-4" aria-label="Candidate preview">
                    <DraftPreview data={form.data} />
                </aside>
            )}
        </form>
    );
}

/**
 * What candidates will see, updated while typing. The correct answer is
 * marked here for the author only.
 */
function DraftPreview({ data }: { data: QuestionFormData }) {
    const choices =
        data.type === 'multiple_choice'
            ? data.choices.map((choice, index) => ({ key: choice.key, letter: choiceLetter(index), text: choice.text, correct: choice.is_correct }))
            : data.type === 'true_false'
              ? [
                    { key: 'true', letter: 'A', text: 'True', correct: data.correct_answer === 'true' },
                    { key: 'false', letter: 'B', text: 'False', correct: data.correct_answer === 'false' },
                ]
              : [];

    return (
        <section className="overflow-hidden rounded-lg border border-line-box bg-surface shadow-sm">
            <div className="flex items-center gap-2 border-b border-line bg-surface-muted px-4 py-3">
                <Eye className="size-4 text-ink-muted" aria-hidden="true" />
                <h2 className="text-sm font-semibold text-ink">Candidate view</h2>
                <span className="ml-auto text-xs text-ink-muted">{pointsLabel(data.points)}</span>
            </div>
            <div className="space-y-4 p-4">
                <p className={cn('whitespace-pre-wrap break-words text-base font-semibold', data.prompt.trim() === '' && 'font-normal text-ink-subtle italic')}>
                    {data.prompt.trim() === '' ? 'Your question appears here.' : data.prompt}
                </p>
                {data.type === 'essay' ? (
                    <div className="h-24 rounded-md border border-dashed border-line-strong bg-surface-muted p-3 text-sm text-ink-subtle">The candidate writes the answer here.</div>
                ) : (
                    <ol className="space-y-2">
                        {choices.map((choice) => (
                            <li key={choice.key} className={cn('flex items-center gap-3 rounded-md border px-3 py-2 text-sm', choice.correct ? 'border-success-border bg-success-bg' : 'border-line')}>
                                <span className="flex size-7 shrink-0 items-center justify-center rounded-full border-2 border-line-strong text-xs font-bold text-ink-muted" aria-hidden="true">
                                    {choice.letter}
                                </span>
                                <span className={cn('min-w-0 flex-1 break-words', choice.text.trim() === '' && 'text-ink-subtle italic')}>
                                    {choice.text.trim() === '' ? `Choice ${choice.letter}` : choice.text}
                                </span>
                                {choice.correct && (
                                    <span className="flex shrink-0 items-center gap-1 text-xs font-semibold text-success-fg">
                                        <CircleCheck className="size-4" aria-hidden="true" />
                                        Correct
                                    </span>
                                )}
                            </li>
                        ))}
                    </ol>
                )}
                <p className="border-t border-line pt-3 text-xs text-ink-muted">The correct answer is marked for you only. Candidates see the question without it.</p>
            </div>
        </section>
    );
}

function pointsLabel(points: string): string {
    const value = points.trim();
    if (value === '') return 'Points not set';

    return value === '1' ? '1 point' : `${value} points`;
}

function typeDescription(type: QuestionTypeValue, limits: QuestionLimits): string {
    switch (type) {
        case 'multiple_choice':
            return `${limits.minChoices}–${limits.maxChoices} choices, one correct. Scored automatically.`;
        case 'true_false':
            return 'A statement that is true or false. Scored automatically.';
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
