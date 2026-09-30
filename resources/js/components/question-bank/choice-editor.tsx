import { CircleAlert, CircleCheck, Plus, Trash2 } from 'lucide-react';
import { useEffect, useId, useState, type MouseEvent } from 'react';
import { newChoice, type ChoiceDraft } from '@/components/question-bank/question-form-data';
import { Button } from '@/components/ui/button';
import { FormField, TextInput } from '@/components/ui/form-field';
import { cn } from '@/lib/cn';
import type { QuestionLimits } from '@/types/question-bank';

interface ChoiceEditorProps {
    choices: ChoiceDraft[];
    limits: QuestionLimits;
    /** Error of the list as a whole, e.g. no correct answer marked. */
    error?: string;
    /** Error of one choice's text, by index (server keys such as choices.2.text). */
    choiceError: (index: number) => string | undefined;
    onChange: (choices: ChoiceDraft[]) => void;
}

/**
 * Answer choices of a multiple choice question (UI_UX_DESIGN.md §55): lettered
 * rows A–F with add and remove, and one "Correct answer" radio per row. The
 * whole row is a tap target for marking the correct answer (§22–23); the
 * correct row is also shown by an icon and bolder text, not by color alone.
 */
export function ChoiceEditor({ choices, limits, error, choiceError, onChange }: ChoiceEditorProps) {
    const groupName = useId();
    const hintId = useId();
    const errorId = useId();
    // The text field to focus after adding or removing a row.
    const [focusKey, setFocusKey] = useState<string | null>(null);

    useEffect(() => {
        if (focusKey === null) {
            return;
        }

        document.querySelector<HTMLInputElement>(`input[data-choice-key="${focusKey}"]`)?.focus();
        setFocusKey(null);
    }, [focusKey]);

    const canAdd = choices.length < limits.maxChoices;
    const canRemove = choices.length > limits.minChoices;

    const markCorrect = (index: number) => onChange(choices.map((choice, position) => ({ ...choice, is_correct: position === index })));
    const changeText = (index: number, text: string) =>
        onChange(choices.map((choice, position) => (position === index ? { ...choice, text } : choice)));

    const add = () => {
        const choice = newChoice();
        setFocusKey(choice.key);
        onChange([...choices, choice]);
    };

    const remove = (index: number) => {
        const next = choices[index + 1] ?? choices[index - 1];
        setFocusKey(next?.key ?? null);
        onChange(choices.filter((_, position) => position !== index));
    };

    return (
        <fieldset aria-describedby={error ? `${hintId} ${errorId}` : hintId} className="flex flex-col gap-3">
            <legend className="sr-only">Answer choices</legend>
            <p id={hintId} className="text-sm text-ink-muted">
                Enter {limits.minChoices} to {limits.maxChoices} choices and mark exactly one as the correct answer. Candidates never see which choice is
                correct.
            </p>
            {error && (
                <p id={errorId} className="flex items-start gap-1.5 text-sm text-danger-fg">
                    <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>{error}</span>
                </p>
            )}

            <ol className="flex flex-col gap-3">
                {choices.map((choice, index) => (
                    <ChoiceRow
                        key={choice.key}
                        choice={choice}
                        letter={choiceLetter(index)}
                        groupName={groupName}
                        error={choiceError(index)}
                        canRemove={canRemove}
                        maxLength={limits.choiceLength}
                        onTextChange={(text) => changeText(index, text)}
                        onMarkCorrect={() => markCorrect(index)}
                        onRemove={() => remove(index)}
                    />
                ))}
            </ol>

            <div className="flex flex-wrap items-center gap-3">
                <Button variant="secondary" icon={<Plus className="size-4" aria-hidden="true" />} onClick={add} disabled={!canAdd}>
                    Add Choice
                </Button>
                <p className="text-sm text-ink-muted">
                    <span className="tabular-nums">{choices.length}</span> of <span className="tabular-nums">{limits.maxChoices}</span> choices
                    {!canAdd && ' (the maximum)'}
                </p>
            </div>
        </fieldset>
    );
}

interface ChoiceRowProps {
    choice: ChoiceDraft;
    letter: string;
    groupName: string;
    error?: string;
    canRemove: boolean;
    maxLength: number;
    onTextChange: (text: string) => void;
    onMarkCorrect: () => void;
    onRemove: () => void;
}

function ChoiceRow({ choice, letter, groupName, error, canRemove, maxLength, onTextChange, onMarkCorrect, onRemove }: ChoiceRowProps) {
    // A tap anywhere on the row marks it correct, except on its own controls.
    const markFromRow = (event: MouseEvent<HTMLLIElement>) => {
        if (event.target instanceof Element && event.target.closest('input, textarea, button, label, a') !== null) {
            return;
        }

        onMarkCorrect();
    };

    return (
        <li
            onClick={markFromRow}
            className={cn(
                'flex cursor-pointer flex-col gap-3 rounded-lg border p-3 transition-colors sm:p-4',
                choice.is_correct ? 'border-success-border bg-success-bg' : 'border-line bg-surface hover:bg-surface-muted',
            )}
        >
            <FormField label={`Choice ${letter}`} required error={error}>
                <TextInput
                    value={choice.text}
                    onChange={(event) => onTextChange(event.target.value)}
                    maxLength={maxLength}
                    autoComplete="off"
                    data-choice-key={choice.key}
                    className="cursor-text"
                />
            </FormField>

            <div className="flex flex-wrap items-center justify-between gap-2">
                <label
                    className={cn(
                        'inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-md px-2 text-sm has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary-600 pointer-coarse:min-h-11',
                        choice.is_correct ? 'font-semibold text-success-fg' : 'font-medium text-ink',
                    )}
                >
                    <input
                        type="radio"
                        name={groupName}
                        checked={choice.is_correct}
                        onChange={onMarkCorrect}
                        className="size-4 shrink-0 accent-primary-600 focus-visible:outline-none pointer-coarse:size-5"
                    />
                    {choice.is_correct && <CircleCheck className="size-4 shrink-0" aria-hidden="true" />}
                    <span>
                        Correct answer<span className="sr-only">: choice {letter}</span>
                    </span>
                </label>

                <Button
                    variant="ghost"
                    size="sm"
                    icon={<Trash2 className="size-4" aria-hidden="true" />}
                    onClick={onRemove}
                    disabled={!canRemove}
                    aria-label={`Remove choice ${letter}`}
                >
                    Remove
                </Button>
            </div>
        </li>
    );
}

/** A for the first choice, B for the second, ... (QuestionChoice::letter()). */
export function choiceLetter(index: number): string {
    return String.fromCharCode(65 + index);
}
