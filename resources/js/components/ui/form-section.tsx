import { useId, type ReactNode } from 'react';

interface FormSectionProps {
    title: string;
    description?: string;
    children: ReactNode;
}

/**
 * Groups related form fields under a heading (UI_UX_DESIGN.md §37).
 */
export function FormSection({ title, description, children }: FormSectionProps) {
    const titleId = useId();

    return (
        <section aria-labelledby={titleId} className="rounded-lg border border-line bg-surface">
            <div className="border-b border-line px-5 py-4">
                <h2 id={titleId} className="text-base font-semibold text-ink">
                    {title}
                </h2>
                {description && <p className="mt-0.5 text-sm text-ink-muted">{description}</p>}
            </div>
            <div className="flex flex-col gap-5 p-5">{children}</div>
        </section>
    );
}

/** Cancel and submit buttons at the end of a form (UI_UX_DESIGN.md §36). */
export function FormActions({ children }: { children: ReactNode }) {
    return <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">{children}</div>;
}
