import { Head, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, BookOpenCheck, Check, ListChecks, PenLine, Plus, SearchX, X } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { BuilderSteps } from '@/components/examinations/builder-steps';
import { WriteQuestionDialog } from '@/components/examinations/write-question-dialog';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { SearchField } from '@/components/ui/filter-bar';
import { FormField, SelectInput, TextInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { cn } from '@/lib/cn';
import { examinationRoutes } from '@/lib/examination-routes';
import type { QuestionBankSubject, QuestionLimits, QuestionTypeOption, StaffQuestion } from '@/types/question-bank';

interface Exam {
    id: number;
    title: string;
    class_subject?: { subject_id: number };
    examination_questions: { question_id: number; points: string }[];
}

type MoveControl = 'up' | 'down';

/** Where keyboard focus goes after the selection changes (null questionId: the list heading). */
interface FocusTarget {
    questionId: number | null;
    control?: MoveControl | 'first';
}

const POINTS_ERROR = /^questions\.\d+\.points$/;

function excerpt(text: string, length = 60): string {
    const singleLine = text.replace(/\s+/g, ' ').trim();

    return singleLine.length > length ? `${singleLine.slice(0, length)}…` : singleLine;
}

interface ExaminationQuestionsProps {
    examination: Exam;
    /** Saved (active) questions of the examination's subject, newest first. */
    questions: StaffQuestion[];
    /** The examination's subject, for Write a New Question. */
    subject: QuestionBankSubject;
    topics: string[];
    types: QuestionTypeOption[];
    limits: QuestionLimits;
}

/**
 * Step 2 of the builder (owner request, 2026-10-03): questions are written
 * here (Write a New Question; each is saved to the question bank and added to
 * this examination), and questions already saved for the subject can be reused.
 */
export default function ExaminationQuestions({ examination, questions, subject, topics, types: questionTypes, limits }: ExaminationQuestionsProps) {
    const [writing, setWriting] = useState(false);
    const form = useForm({ questions: examination.examination_questions.map((item) => ({ question_id: item.question_id, points: item.points })) });
    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState('');
    const [announcement, setAnnouncement] = useState('');
    const selectedHeadingRef = useRef<HTMLHeadingElement>(null);
    const bankListRef = useRef<HTMLDivElement>(null);
    const itemRefs = useRef(new Map<number, HTMLLIElement>());
    const pendingFocus = useRef<FocusTarget | null>(null);
    const errors = form.errors as Record<string, string | undefined>;
    const selected = form.data.questions;
    const selectedIds = new Set(selected.map((item) => item.question_id));
    const matches = questions.filter((question) => question.prompt.toLowerCase().includes(search.toLowerCase()));
    const otherErrors = Object.entries(errors)
        .filter(([key, message]) => !POINTS_ERROR.test(key) && message !== undefined)
        .map(([, message]) => message);

    // Restore focus after a reorder or removal re-renders the selected list.
    useEffect(() => {
        const target = pendingFocus.current;
        if (target === null) {
            return;
        }
        pendingFocus.current = null;

        if (target.questionId === null) {
            selectedHeadingRef.current?.focus();

            return;
        }
        const item = itemRefs.current.get(target.questionId);
        if (item === undefined) {
            return;
        }
        const preferred = target.control && target.control !== 'first' ? item.querySelector<HTMLButtonElement>(`[data-control="${target.control}"]:not(:disabled)`) : null;
        const firstEnabled = item.querySelector<HTMLButtonElement>('button:not(:disabled)');
        (preferred ?? firstEnabled ?? item).focus();
    }, [selected]);

    const questionFor = (questionId: number) => questions.find((row) => row.id === questionId);

    const add = (question: StaffQuestion, button: HTMLButtonElement) => {
        form.setData('questions', [...selected, { question_id: question.id, points: question.points }]);
        setAnnouncement(`Added as question ${selected.length + 1}: ${excerpt(question.prompt)}. ${selected.length + 1} selected.`);
        // The Add button disables itself (and would drop focus), so focus the nearest question that can still be added.
        window.requestAnimationFrame(() => {
            const buttons = Array.from(bankListRef.current?.querySelectorAll<HTMLButtonElement>('button[data-add]') ?? []);
            const clicked = buttons.indexOf(button);
            const following = buttons.slice(clicked + 1).find((candidate) => !candidate.disabled);
            const preceding = buttons.slice(0, Math.max(clicked, 0)).reverse().find((candidate) => !candidate.disabled);
            (following ?? preceding ?? selectedHeadingRef.current)?.focus();
        });
    };

    const move = (index: number, direction: -1 | 1) => {
        const items = [...selected];
        const current = items[index];
        const other = items[index + direction];
        if (current === undefined || other === undefined) {
            return;
        }
        items[index] = other;
        items[index + direction] = current;
        pendingFocus.current = { questionId: current.question_id, control: direction === -1 ? 'up' : 'down' };
        form.setData('questions', items);
        setAnnouncement(`Question ${index + 1} moved to position ${index + 1 + direction} of ${items.length}.`);
    };

    const remove = (index: number) => {
        const items = selected.filter((_, i) => i !== index);
        const neighbour = items[index] ?? items[index - 1];
        pendingFocus.current = { questionId: neighbour?.question_id ?? null, control: 'first' };
        form.setData('questions', items);
        setAnnouncement(`Question ${index + 1} removed. ${items.length} selected.`);
    };

    const setPoints = (index: number, points: string) => {
        form.setData(
            'questions',
            selected.map((row, i) => (i === index ? { ...row, points } : row)),
        );
    };

    const totalPoints = selected.reduce((sum, item) => sum + (Number(item.points) || 0), 0);
    const types = Array.from(new Map(questions.map((question) => [question.type.value, question.type.label])).entries());
    const shown = typeFilter === '' ? matches : matches.filter((question) => question.type.value === typeFilter);
    const addable = shown.filter((question) => !selectedIds.has(question.id));

    // A question written in the dialog is already saved and added on the server; add it here too, keeping any unsaved changes.
    const addWritten = (savedQuestions: StaffQuestion[]) => {
        const known = new Set(questions.map((question) => question.id));
        const written = savedQuestions.filter((question) => !known.has(question.id) && !selectedIds.has(question.id));
        if (written.length === 0) {
            return;
        }
        const wasDirty = form.isDirty;
        const next = [...selected, ...written.map((question) => ({ question_id: question.id, points: question.points }))];
        form.setData('questions', next);
        if (!wasDirty) {
            form.setDefaults('questions', next);
        }
        setAnnouncement(`Question saved and added as question ${next.length}.`);
    };

    const writeButton = (
        <Button type="button" onClick={() => setWriting(true)} icon={<PenLine className="size-4" aria-hidden="true" />}>
            Write a New Question
        </Button>
    );

    const addAllShown = () => {
        if (addable.length === 0) return;
        form.setData('questions', [...selected, ...addable.map((question) => ({ question_id: question.id, points: question.points }))]);
        setAnnouncement(`${addable.length} questions added. ${selected.length + addable.length} selected.`);
    };

    return (
        <>
            <Head title={`Questions · ${examination.title}`} />
            <PageHeader
                title="Questions"
                description={`${examination.title} · ${subject.name}`}
                breadcrumbs={[
                    { label: 'Examinations', href: examinationRoutes.index() },
                    { label: examination.title, href: examinationRoutes.show(examination.id) },
                    { label: 'Questions' },
                ]}
                actions={writeButton}
            />

            <BuilderSteps current="questions" examinationId={examination.id} questionCount={selected.length} />

            <div role="status" aria-live="polite" className="sr-only">
                {announcement}
            </div>

            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(examinationRoutes.questions.update(examination.id));
                }}
            >
                <div className="grid gap-6 lg:grid-cols-2 lg:items-start">
                    <Panel title="Reuse Saved Questions" description={`Questions already written for ${subject.name}, in earlier quizzes and examinations or in the Question Bank. Add any to use it again.`}>
                        {questions.length === 0 ? (
                            <EmptyState
                                icon={BookOpenCheck}
                                headingLevel="h3"
                                title="No saved questions yet"
                                description="Questions you write for this subject are kept here, so later quizzes and examinations can reuse them."
                            />
                        ) : (
                            <>
                                <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem]">
                                    <SearchField label="Search questions" placeholder="Search by question text…" value={search} onChange={setSearch} />
                                    <FormField label="Type">
                                        <SelectInput value={typeFilter} onChange={(event) => setTypeFilter(event.target.value)}>
                                            <option value="">All types</option>
                                            {types.map(([value, label]) => (
                                                <option key={value} value={value}>
                                                    {label}
                                                </option>
                                            ))}
                                        </SelectInput>
                                    </FormField>
                                </div>
                                <div className="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm text-ink-muted">
                                    <span>
                                        Showing {shown.length} of {questions.length}
                                    </span>
                                    <Button type="button" variant="ghost" size="sm" disabled={addable.length === 0} onClick={addAllShown}>
                                        Add All Shown ({addable.length})
                                    </Button>
                                </div>
                                <div ref={bankListRef} className="mt-2 max-h-[60vh] space-y-2 overflow-y-auto pr-1">
                                    {shown.map((question) => {
                                        const added = selectedIds.has(question.id);

                                        return (
                                            <article className={cn('flex items-start gap-3 rounded-lg border p-3', added ? 'border-success-border bg-success-bg/50' : 'border-line')} key={question.id}>
                                                <div className="min-w-0 flex-1">
                                                    <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-muted">
                                                        <span className="rounded bg-surface-muted px-1.5 py-0.5 font-medium text-ink">{question.type.label}</span>
                                                        {question.topic && <span>{question.topic.name}</span>}
                                                        <span>{question.points === '1' ? '1 point' : `${question.points} points`}</span>
                                                        {question.media.length > 0 && <span>{question.media.length === 1 ? '1 media file' : `${question.media.length} media files`}</span>}
                                                    </p>
                                                    <p className="mt-1.5 line-clamp-3 whitespace-pre-wrap break-words text-sm">{question.prompt}</p>
                                                </div>
                                                <Button
                                                    type="button"
                                                    variant={added ? 'ghost' : 'secondary'}
                                                    size="sm"
                                                    data-add
                                                    disabled={added}
                                                    icon={added ? <Check className="size-4" aria-hidden="true" /> : <Plus className="size-4" aria-hidden="true" />}
                                                    aria-label={added ? `Already added: ${excerpt(question.prompt)}` : `Add question: ${excerpt(question.prompt)}`}
                                                    onClick={(event) => add(question, event.currentTarget)}
                                                >
                                                    {added ? 'Added' : 'Add'}
                                                </Button>
                                            </article>
                                        );
                                    })}
                                    {shown.length === 0 && (
                                        <EmptyState icon={SearchX} headingLevel="h3" title="No questions match" description="Try a different word or another type." />
                                    )}
                                </div>
                            </>
                        )}
                    </Panel>

                    <section aria-labelledby="selected-questions-heading" className="institution-panel min-w-0 rounded-xl border border-line-box bg-surface lg:sticky lg:top-4">
                        <header className="flex flex-wrap items-start justify-between gap-3 rounded-t-xl border-b border-line bg-primary-50/70 px-5 py-4">
                            <div>
                                <h2 id="selected-questions-heading" ref={selectedHeadingRef} tabIndex={-1} className="text-base font-bold text-primary-900 focus:outline-none">
                                    In This Examination
                                </h2>
                                <p className="mt-0.5 text-sm text-ink-muted">Candidates get them in this order unless question order is randomized.</p>
                            </div>
                            <p className="text-right text-sm">
                                <span className="block text-lg font-bold tabular-nums text-primary-900">{selected.length}</span>
                                <span className="text-ink-muted">{selected.length === 1 ? 'question' : 'questions'} · {formatPoints(totalPoints)}</span>
                            </p>
                        </header>
                        <div className="max-h-[60vh] overflow-y-auto p-4">
                            {selected.length === 0 ? (
                                <EmptyState icon={ListChecks} headingLevel="h3" title="No questions yet" description="Write a new question, or add saved ones from the list." action={writeButton} />
                            ) : (
                                <ol className="space-y-2">
                                    {selected.map((item, index) => {
                                        const number = index + 1;
                                        const question = questionFor(item.question_id);
                                        const pointsError = errors[`questions.${index}.points`];

                                        return (
                                            <li
                                                key={item.question_id}
                                                ref={(element) => {
                                                    if (element) {
                                                        itemRefs.current.set(item.question_id, element);
                                                    } else {
                                                        itemRefs.current.delete(item.question_id);
                                                    }
                                                }}
                                                tabIndex={-1}
                                                className={cn('rounded-lg border p-3 focus:outline-none', pointsError || question === undefined ? 'border-danger-border' : 'border-line')}
                                            >
                                                <div className="flex items-start gap-3">
                                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-600 text-sm font-bold text-white" aria-hidden="true">
                                                        {number}
                                                    </span>
                                                    <div className="min-w-0 flex-1">
                                                        <p className="line-clamp-2 whitespace-pre-wrap break-words text-sm">
                                                            <span className="sr-only">Question {number}: </span>
                                                            {question?.prompt ?? 'This question is inactive or unavailable. Remove it before saving.'}
                                                        </p>
                                                        {question && <p className="mt-0.5 text-xs text-ink-muted">{question.type.label}</p>}
                                                    </div>
                                                </div>
                                                <div className="mt-2 flex flex-wrap items-end justify-between gap-2 pl-11">
                                                    <FormField label={<span>Points<span className="sr-only"> for question {number}</span></span>} required error={pointsError} className="w-28">
                                                        <TextInput type="number" min="0.01" max="100" step="0.01" required value={item.points} onChange={(event) => setPoints(index, event.target.value)} className="tabular-nums" />
                                                    </FormField>
                                                    <div className="flex gap-1">
                                                        <IconButton control="up" disabled={index === 0} label={`Move question ${number} up`} onClick={() => move(index, -1)}>
                                                            <ArrowUp className="size-4" aria-hidden="true" />
                                                        </IconButton>
                                                        <IconButton control="down" disabled={index === selected.length - 1} label={`Move question ${number} down`} onClick={() => move(index, 1)}>
                                                            <ArrowDown className="size-4" aria-hidden="true" />
                                                        </IconButton>
                                                        <IconButton label={`Remove question ${number}`} danger onClick={() => remove(index)}>
                                                            <X className="size-4" aria-hidden="true" />
                                                        </IconButton>
                                                    </div>
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ol>
                            )}
                        </div>
                    </section>
                </div>

                {otherErrors.length > 0 && (
                    <Alert tone="danger" title="The questions could not be saved" className="mt-5">
                        <ul className="space-y-1">
                            {otherErrors.map((message) => (
                                <li key={message}>{message}</li>
                            ))}
                        </ul>
                    </Alert>
                )}

                <div className="sticky bottom-0 z-10 mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line-box bg-surface/95 px-4 py-3 shadow-lg backdrop-blur">
                    <p className="text-sm">
                        <span className="font-semibold">
                            {selected.length} {selected.length === 1 ? 'question' : 'questions'} · {formatPoints(totalPoints)}
                        </span>
                        <span className={cn('ml-2', form.isDirty ? 'text-warning-fg' : 'text-ink-muted')}>{form.isDirty ? 'Unsaved changes' : 'No changes'}</span>
                    </p>
                    <div className="flex gap-2">
                        <ButtonLink href={examinationRoutes.show(examination.id)} variant="secondary">
                            Cancel
                        </ButtonLink>
                        <Button type="submit" loading={form.processing}>
                            Save and Review
                        </Button>
                    </div>
                </div>
            </form>

            {/* Outside the form above: a form cannot contain another form. */}
            <WriteQuestionDialog
                open={writing}
                examinationId={examination.id}
                subject={subject}
                topics={topics}
                types={questionTypes}
                limits={limits}
                onSaved={addWritten}
                onClose={() => setWriting(false)}
            />
        </>
    );
}

function formatPoints(points: number): string {
    const value = Number.isInteger(points) ? String(points) : points.toFixed(2);

    return points === 1 ? '1 point' : `${value} points`;
}

function IconButton({ children, label, onClick, disabled = false, control, danger = false }: { children: ReactNode; label: string; onClick: () => void; disabled?: boolean; control?: MoveControl; danger?: boolean }) {
    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            data-control={control}
            disabled={disabled}
            onClick={onClick}
            className={cn(
                'flex size-10 items-center justify-center rounded-md border border-line-strong text-ink-muted disabled:cursor-not-allowed disabled:opacity-40 pointer-coarse:size-11',
                danger ? 'hover:border-danger-border hover:bg-danger-bg hover:text-danger-fg' : 'hover:bg-surface-muted hover:text-ink',
            )}
        >
            {children}
        </button>
    );
}
