import { ArrowDown, ArrowRight, CircleCheck, TriangleAlert, type LucideIcon } from 'lucide-react';
import { cn } from '@/lib/cn';

export interface FlowStep {
    /** Anchor of the section below that explains and configures the step. */
    href: string;
    title: string;
    /** What the step does, in one plain sentence. */
    description: string;
    /** "Set up", or what is missing. Null for steps with nothing to configure. */
    status: { ready: boolean; text: string } | null;
    icon: LucideIcon;
}

/**
 * The order in which the system turns recorded scores into a candidate's
 * result, as numbered steps with their setup status. Text first, so the
 * flow reads the same without color or icons (UI_UX_DESIGN.md §47).
 */
export function SetupFlow({ steps }: { steps: FlowStep[] }) {
    return (
        <ol className="grid gap-2 xl:grid-cols-[repeat(5,minmax(0,1fr))] xl:gap-0">
            {steps.map((step, index) => {
                const Icon = step.icon;
                const isLast = index === steps.length - 1;

                return (
                    <li key={step.href} className="flex min-w-0 flex-col items-stretch xl:flex-row xl:items-stretch">
                        <a
                            href={step.href}
                            className="flex min-w-0 flex-1 flex-col gap-1.5 break-words rounded-lg border border-line bg-surface px-4 py-3 hover:border-primary-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600"
                        >
                            <span className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-ink-muted">
                                <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary-50 text-sm font-semibold text-primary-800 tabular-nums">
                                    {index + 1}
                                </span>
                                <Icon className="size-4" aria-hidden="true" />
                            </span>
                            <span className="font-semibold text-ink">{step.title}</span>
                            <span className="text-sm text-ink-muted">{step.description}</span>
                            {step.status !== null && (
                                <span
                                    className={cn(
                                        'mt-auto flex items-start gap-1.5 pt-1 text-sm font-medium',
                                        step.status.ready ? 'text-success-fg' : 'text-warning-fg',
                                    )}
                                >
                                    {step.status.ready ? (
                                        <CircleCheck className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                    ) : (
                                        <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                    )}
                                    {step.status.text}
                                </span>
                            )}
                        </a>
                        {!isLast && (
                            <span className="flex items-center justify-center py-1 text-ink-subtle xl:px-1 xl:py-0" aria-hidden="true">
                                <ArrowDown className="size-4 xl:hidden" />
                                <ArrowRight className="hidden size-4 xl:block" />
                            </span>
                        )}
                    </li>
                );
            })}
        </ol>
    );
}
