import { useId, type ReactNode } from 'react';

interface FormSectionProps {
    title: string;
    description?: string;
    /** Shows a numbered step badge, for forms filled in a fixed order. */
    step?: number;
    children: ReactNode;
}

/**
 * Groups related form fields under a heading (UI_UX_DESIGN.md §37).
 */
export function FormSection({ title, description, step, children }: FormSectionProps) {
    const titleId = useId();

    return (
        <section aria-labelledby={titleId} className="rounded-lg border border-line bg-surface">
            <div className="flex items-start gap-3 border-b border-line px-5 py-4">
                {step !== undefined && (
                    <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-primary-600 text-sm font-bold text-white" aria-hidden="true">
                        {step}
                    </span>
                )}
                <div className="min-w-0">
                    <h2 id={titleId} className="text-base font-semibold text-ink">
                        {step !== undefined && <span className="sr-only">Step {step}: </span>}
                        {title}
                    </h2>
                    {description && <p className="mt-0.5 text-sm text-ink-muted">{description}</p>}
                </div>
            </div>
            <div className="flex flex-col gap-5 p-5">{children}</div>
        </section>
    );
}

/** Cancel and submit buttons at the end of a form (UI_UX_DESIGN.md §36). */
export function FormActions({ children }: { children: ReactNode }) {
    return <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">{children}</div>;
}
