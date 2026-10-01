import { Link } from '@inertiajs/react';
import { ArrowRight, type LucideIcon } from 'lucide-react';
import { useId, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

/**
 * Building blocks of the candidate portal (UI_UX_DESIGN.md §20, §22, §45):
 * fewer, larger elements than the staff area, an icon that names each
 * section, generous spacing, and large touch targets for tablets.
 */

/** The page title: a large icon, the title and one short sentence. */
export function PortalHeading({ icon: Icon, title, description, actions }: { icon: LucideIcon; title: string; description?: ReactNode; actions?: ReactNode }) {
    return (
        <header className="mb-8 flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex min-w-0 items-center gap-4">
                <span className="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-primary-100 text-primary-700 shadow-sm">
                    <Icon className="size-7" aria-hidden="true" />
                </span>
                <div className="min-w-0">
                    <h1 className="text-2xl font-bold tracking-tight text-primary-900 sm:text-3xl">{title}</h1>
                    {description && <p className="mt-1 max-w-2xl text-base leading-relaxed text-ink-muted">{description}</p>}
                </div>
            </div>
            {actions && <div className="flex shrink-0 flex-wrap gap-3">{actions}</div>}
        </header>
    );
}

interface PortalSectionProps {
    icon: LucideIcon;
    title: string;
    description?: ReactNode;
    action?: ReactNode;
    children: ReactNode;
    id?: string;
    /** Content runs to the card's edges (lists with their own padding). */
    flush?: boolean;
    className?: string;
}

/** A titled section: one subject per card, with room around everything. */
export function PortalSection({ icon: Icon, title, description, action, children, id, flush = false, className }: PortalSectionProps) {
    const headingId = useId();

    return (
        <section id={id} aria-labelledby={headingId} className={cn('scroll-mt-6 overflow-hidden rounded-2xl border border-line bg-surface shadow-sm', className)}>
            <div className="flex flex-col gap-3 px-5 pt-6 sm:flex-row sm:items-start sm:justify-between sm:px-7">
                <div className="flex min-w-0 items-start gap-3">
                    <span className="mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-700">
                        <Icon className="size-5" aria-hidden="true" />
                    </span>
                    <div className="min-w-0">
                        <h2 id={headingId} className="text-xl font-semibold text-primary-900">
                            {title}
                        </h2>
                        {description && <p className="mt-1 text-sm leading-relaxed text-ink-muted">{description}</p>}
                    </div>
                </div>
                {action && <div className="shrink-0">{action}</div>}
            </div>
            <div className={flush ? 'mt-5 border-t border-line' : 'px-5 pb-7 pt-6 sm:px-7'}>{children}</div>
        </section>
    );
}

/** One figure with its label: used in a <dl>. */
export function StatTile({ icon: Icon, label, value, hint }: { icon: LucideIcon; label: string; value: ReactNode; hint?: ReactNode }) {
    return (
        <div className="flex items-start gap-4 rounded-xl border border-line bg-surface-muted/60 p-5">
            <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-surface text-primary-700 shadow-sm">
                <Icon className="size-5" aria-hidden="true" />
            </span>
            <div className="min-w-0">
                <dt className="text-sm font-medium text-ink-muted">{label}</dt>
                <dd className="mt-1 text-2xl font-bold text-ink tabular-nums">{value}</dd>
                {hint && <dd className="mt-1 text-sm text-ink-muted">{hint}</dd>}
            </div>
        </div>
    );
}

interface PortalTileProps {
    href: string;
    icon: LucideIcon;
    title: string;
    /** The one thing to know about this section. */
    children: ReactNode;
    cta: string;
}

/** A large link to a portal page, with its key figure. */
export function PortalTile({ href, icon: Icon, title, children, cta }: PortalTileProps) {
    return (
        <Link
            href={href}
            className="group flex min-h-48 flex-col rounded-2xl border border-line bg-surface p-6 shadow-sm transition-colors hover:border-primary-600 hover:bg-primary-50/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 motion-reduce:transition-none"
        >
            <span className="flex size-12 items-center justify-center rounded-xl bg-primary-100 text-primary-700">
                <Icon className="size-6" aria-hidden="true" />
            </span>
            <span className="mt-4 text-lg font-semibold text-primary-900">{title}</span>
            <span className="mt-2 flex-1 text-sm text-ink">{children}</span>
            <span className="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-primary-700">
                {cta}
                <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5 motion-reduce:transition-none" aria-hidden="true" />
            </span>
        </Link>
    );
}

/** A friendly empty message inside a section. */
export function PortalEmpty({ icon: Icon, title, children }: { icon: LucideIcon; title: string; children?: ReactNode }) {
    return (
        <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed border-line-strong/60 px-6 py-10 text-center">
            <span className="flex size-12 items-center justify-center rounded-full bg-surface-muted text-ink-muted">
                <Icon className="size-6" aria-hidden="true" />
            </span>
            <p className="text-base font-semibold text-ink">{title}</p>
            {children && <p className="max-w-md text-sm text-ink-muted">{children}</p>}
        </div>
    );
}
