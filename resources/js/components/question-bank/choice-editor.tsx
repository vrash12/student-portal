import { Check, CircleAlert, CircleCheck, Plus, Trash2 } from 'lucide-react';
import { useEffect, useId, useState, type KeyboardEvent } from 'react';
import { newChoice, type ChoiceDraft } from '@/components/question-bank/question-form-data';
import { Button } from '@/components/ui/button';
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
 * Answer choices of a multiple choice question (UI_UX_DESIGN.md §55): one
 * compact row per choice, A–F. The letter button marks the correct answer
 * (a radio group); the correct row also shows a check and "Correct answer"
 * text, not only color. Enter moves to the next choice (adding one at the
 * end) instead of submitting the form.
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
    const hasCorrect = choices.some((choice) => choice.is_correct);

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

    const nextOnEnter = (index: number) => (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key !== 'Enter') {
            return;
        }
        event.preventDefault();
        const next = choices[index + 1];
        if (next !== undefined) {
            setFocusKey(next.key);
        } else if (canAdd) {
            add();
        }
    };

    return (
        <fieldset aria-describedby={error ? `${hintId} ${errorId}` : hintId} className="flex flex-col gap-3">
            <legend className="sr-only">Answer choices</legend>
            <p id={hintId} className="text-sm text-ink-muted">
                Type {limits.minChoices} to {limits.maxChoices} choices, then <strong className="font-semibold text-ink">select the letter of the correct answer</strong>. Candidates never
                see which choice is correct.
            </p>
            {error && (
                <p id={errorId} className="flex items-start gap-1.5 text-sm text-danger-fg">
                    <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>{error}</span>
                </p>
            )}

            <ol className="flex flex-col gap-2">
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
                        onKeyDown={nextOnEnter(index)}
                    />
                ))}
            </ol>

            <div className="flex flex-wrap items-center justify-between gap-3">
                <Button variant="secondary" icon={<Plus className="size-4" aria-hidden="true" />} onClick={add} disabled={!canAdd}>
                    Add Choice
                </Button>
                <p className={cn('flex items-center gap-1.5 text-sm', hasCorrect ? 'text-success-fg' : 'text-warning-fg')}>
                    {hasCorrect ? <CircleCheck className="size-4" aria-hidden="true" /> : <CircleAlert className="size-4" aria-hidden="true" />}
                    {hasCorrect ? `Correct answer: ${choiceLetter(choices.findIndex((choice) => choice.is_correct))}` : 'No correct answer selected yet'}
                    <span className="text-ink-muted">
                        {' '}
                        · <span className="tabular-nums">{choices.length}</span> of <span className="tabular-nums">{limits.maxChoices}</span> choices
                    </span>
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
    onKeyDown: (event: KeyboardEvent<HTMLInputElement>) => void;
}

function ChoiceRow({ choice, letter, groupName, error, canRemove, maxLength, onTextChange, onMarkCorrect, onRemove, onKeyDown }: ChoiceRowProps) {
    const inputId = useId();
    const errorId = useId();

    return (
        <li
            className={cn(
                'rounded-lg border p-2 transition-colors sm:p-3',
                choice.is_correct ? 'border-success-border bg-success-bg' : 'border-line bg-surface',
                error && 'border-danger-border',
            )}
        >
            <div className="flex items-center gap-2 sm:gap-3">
                <label
                    className={cn(
                        'relative flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-full border-2 text-base font-bold transition-colors has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary-600',
                        choice.is_correct ? 'border-success-fg bg-success-fg text-white' : 'border-line-strong bg-surface text-ink-muted hover:border-primary-600 hover:text-primary-700',
                    )}
                    title={choice.is_correct ? `Choice ${letter} is the correct answer` : `Mark choice ${letter} as the correct answer`}
                >
                    <input type="radio" name={groupName} checked={choice.is_correct} onChange={onMarkCorrect} className="sr-only" />
                    <span className="sr-only">Correct answer: choice {letter}</span>
                    {choice.is_correct ? <Check className="size-5" aria-hidden="true" /> : <span aria-hidden="true">{letter}</span>}
                </label>

                <div className="min-w-0 flex-1">
                    <label htmlFor={inputId} className="sr-only">
                        Choice {letter}
                    </label>
                    <input
                        id={inputId}
                        value={choice.text}
                        onChange={(event) => onTextChange(event.target.value)}
                        onKeyDown={onKeyDown}
                        maxLength={maxLength}
                        autoComplete="off"
                        placeholder={`Choice ${letter}`}
                        data-choice-key={choice.key}
                        aria-invalid={error ? true : undefined}
                        aria-describedby={error ? errorId : undefined}
                        className="block h-11 w-full rounded-md border border-line-strong bg-surface px-3 text-base text-ink placeholder:text-ink-subtle focus-visible:border-primary-600 focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-primary-600"
                    />
                </div>

                <button
                    type="button"
                    onClick={onRemove}
                    disabled={!canRemove}
                    aria-label={`Remove choice ${letter}`}
                    title={canRemove ? `Remove choice ${letter}` : 'A question needs at least the minimum number of choices'}
                    className="flex size-11 shrink-0 items-center justify-center rounded-md text-ink-muted hover:bg-danger-bg hover:text-danger-fg disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-ink-muted"
                >
                    <Trash2 className="size-4" aria-hidden="true" />
                </button>
            </div>

            {choice.is_correct && (
                <p className="mt-1.5 flex items-center gap-1.5 pl-14 text-sm font-semibold text-success-fg">
                    <CircleCheck className="size-4" aria-hidden="true" />
                    Correct answer
                </p>
            )}
            {error && (
                <p id={errorId} className="mt-1.5 flex items-start gap-1.5 pl-14 text-sm text-danger-fg">
                    <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>{error}</span>
                </p>
            )}
        </li>
    );
}

/** A for the first choice, B for the second, ... (QuestionChoice::letter()). */
export function choiceLetter(index: number): string {
    return String.fromCharCode(65 + index);
}
