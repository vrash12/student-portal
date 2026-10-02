import { ChevronDown } from 'lucide-react';
import { useId, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { useCollapsible } from '@/lib/use-collapsible';

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
    /** Titled panels open and close from their header (default); false keeps the panel always open. */
    collapsible?: boolean;
    /** Start closed (true) or open (false); by default the page decides (see StartCollapsed). */
    defaultCollapsed?: boolean;
    /** Anchor of the whole panel, so in-page links land on its heading. */
    id?: string;
}

/**
 * Bordered surface for a meaningful group of content (UI_UX_DESIGN.md §46).
 * Selecting the header of a titled panel shows or hides its content.
 */
export function Panel({ title, description, actions, children, className, bodyClassName, headingLevel: Heading = 'h2', collapsible = true, defaultCollapsed, id }: PanelProps) {
    const titleId = useId();
    const bodyId = useId();
    const canCollapse = collapsible && title !== undefined;
    const { collapsed, toggle } = useCollapsible(title ?? '', canCollapse, defaultCollapsed);

    return (
        <section id={id} className={cn('institution-panel min-w-0 rounded-xl border border-line-box bg-surface', className)} aria-labelledby={title ? titleId : undefined}>
            {title && (
                <header
                    className={cn(
                        'flex flex-col gap-3 rounded-t-xl bg-primary-50/70 sm:flex-row sm:items-start sm:justify-between',
                        collapsed ? 'rounded-b-xl' : 'border-b border-line-box',
                        canCollapse ? 'transition-colors hover:bg-primary-100/70' : 'px-5 py-4',
                    )}
                >
                    {canCollapse ? (
                        // The heading holds the button (accordion pattern); the description opens it too.
                        <div
                            className="min-w-0 flex-1 cursor-pointer px-5 py-4"
                            onClick={(event) => {
                                // Links inside the description keep working on their own.
                                if (!(event.target instanceof Element && event.target.closest('a, button, input, select, textarea'))) {
                                    toggle();
                                }
                            }}
                        >
                            <Heading id={titleId} className="text-base font-bold text-primary-900">
                                <button
                                    type="button"
                                    onClick={toggle}
                                    aria-expanded={!collapsed}
                                    aria-controls={bodyId}
                                    className="-mx-1 inline-flex items-start gap-2.5 rounded-md px-1 text-left"
                                >
                                    <ChevronDown
                                        className={cn('mt-0.5 size-5 shrink-0 text-primary-700 transition-transform motion-reduce:transition-none', collapsed && '-rotate-90')}
                                        aria-hidden="true"
                                    />
                                    {title}
                                </button>
                            </Heading>
                            {description && <p className="mt-0.5 pl-7.5 text-sm text-ink-muted">{description}</p>}
                        </div>
                    ) : (
                        <div className="min-w-0">
                            <Heading id={titleId} className="text-base font-bold text-primary-900">
                                {title}
                            </Heading>
                            {description && <p className="mt-0.5 text-sm text-ink-muted">{description}</p>}
                        </div>
                    )}
                    {actions && !collapsed && <div className={cn('flex shrink-0 items-center gap-2', canCollapse && 'px-5 pb-4 sm:py-4 sm:pl-0')}>{actions}</div>}
                </header>
            )}
            <div id={bodyId} hidden={collapsed} className={bodyClassName ?? 'p-5'}>
                {children}
            </div>
        </section>
    );
}
