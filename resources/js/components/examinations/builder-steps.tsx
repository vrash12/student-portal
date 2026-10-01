import { Link } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { examinationRoutes } from '@/lib/examination-routes';
import { cn } from '@/lib/cn';

export type BuilderStep = 'settings' | 'questions' | 'review';

interface BuilderStepsProps {
    current: BuilderStep;
    /** Unset while creating: later steps are not reachable yet. */
    examinationId?: number;
    /** Number of selected questions, to mark the questions step done. */
    questionCount?: number;
}

const STEPS: Array<{ key: BuilderStep; label: string; hint: string }> = [
    { key: 'settings', label: 'Settings', hint: 'Title, time, schedule, rules' },
    { key: 'questions', label: 'Questions', hint: 'Choose from the Question Bank' },
    { key: 'review', label: 'Review and Publish', hint: 'Check, then publish to candidates' },
];

/**
 * Where a draft examination is in its three steps (UI_UX_DESIGN.md §56), so
 * instructors always see what comes next. Each reached step links to its page.
 */
export function BuilderSteps({ current, examinationId, questionCount = 0 }: BuilderStepsProps) {
    const currentIndex = STEPS.findIndex((step) => step.key === current);

    const hrefFor = (step: BuilderStep): string | null => {
        if (examinationId === undefined) return null;
        if (step === 'settings') return examinationRoutes.edit(examinationId);
        if (step === 'questions') return examinationRoutes.questions.edit(examinationId);

        return examinationRoutes.show(examinationId);
    };

    return (
        <nav aria-label="Examination builder steps" className="mb-6">
            <ol className="grid gap-2 sm:grid-cols-3">
                {STEPS.map((step, index) => {
                    const isCurrent = index === currentIndex;
                    const done = index < currentIndex || (step.key === 'settings' && examinationId !== undefined) || (step.key === 'questions' && questionCount > 0);
                    const href = isCurrent ? null : hrefFor(step.key);
                    const body = (
                        <>
                            <span
                                className={cn(
                                    'flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-bold',
                                    isCurrent ? 'bg-primary-600 text-white' : done ? 'bg-success-bg text-success-fg ring-1 ring-success-border' : 'bg-surface-muted text-ink-muted ring-1 ring-line',
                                )}
                                aria-hidden="true"
                            >
                                {done && !isCurrent ? <Check className="size-4" /> : index + 1}
                            </span>
                            <span className="min-w-0">
                                <span className="block text-sm font-semibold">
                                    {step.label}
                                    {done && !isCurrent && <span className="sr-only"> (done)</span>}
                                </span>
                                <span className="block truncate text-xs text-ink-muted">{step.hint}</span>
                            </span>
                        </>
                    );
                    const classes = cn(
                        'flex min-h-14 items-center gap-3 rounded-lg border px-3 py-2',
                        isCurrent ? 'border-primary-600 bg-primary-50 text-primary-900' : 'border-line bg-surface text-ink',
                    );

                    return (
                        <li key={step.key}>
                            {href !== null ? (
                                <Link href={href} className={cn(classes, 'hover:border-primary-600 hover:bg-primary-50/50')}>
                                    {body}
                                </Link>
                            ) : (
                                <span className={classes} aria-current={isCurrent ? 'step' : undefined}>
                                    {body}
                                </span>
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
