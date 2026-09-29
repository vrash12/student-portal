import { useId, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface PanelProps {
    title?: string;
    description?: ReactNode;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
    /** Override body padding, e.g. `p-0` for edge-to-edge tables. */
    bodyClassName?: string;
    /** Use h3 when the panel sits inside another titled section. */
    headingLevel?: 'h2' | 'h3';
}

/**
 * Bordered surface for a meaningful group of content (UI_UX_DESIGN.md §46).
 */
export function Panel({ title, description, actions, children, className, bodyClassName, headingLevel: Heading = 'h2' }: PanelProps) {
    const titleId = useId();

    return (
        <section
            className={cn('rounded-lg border border-line bg-surface', className)}
            aria-labelledby={title ? titleId : undefined}
        >
            {title && (
                <header className="flex flex-col gap-3 border-b border-line px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0">
                        <Heading id={titleId} className="text-base font-semibold text-ink">
                            {title}
                        </Heading>
                        {description && <p className="mt-0.5 text-sm text-ink-muted">{description}</p>}
                    </div>
                    {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
                </header>
            )}
            <div className={bodyClassName ?? 'p-5'}>{children}</div>
        </section>
    );
}
