import { Link, usePage } from '@inertiajs/react';
import { ChevronRight, type LucideIcon } from 'lucide-react';
import { useContext, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { PageHeaderSlot } from '@/components/ui/page-header-slot';
import { cn } from '@/lib/cn';
import { isActivePath, isVisibleItem, staffNavigation } from '@/lib/navigation';
import { usePermissions } from '@/lib/permissions';

export interface BreadcrumbItem {
    label: string;
    href?: string;
}

interface PageHeaderProps {
    title: string;
    description?: ReactNode;
    /** Primary page action(s), placed top-right (UI_UX_DESIGN.md §15). */
    actions?: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
}

/**
 * The icon of the staff navigation item the current page belongs to, shown
 * in the header. The section name above the title was removed (owner request, 2026-10-03).
 */
function useSectionIcon(): LucideIcon | null {
    const { url } = usePage();
    const { can } = usePermissions();
    for (const matcher of [
        (href: string) => isActivePath(url, href),
        (_href: string, activeFor?: string[]) => activeFor?.some((prefix) => isActivePath(url, prefix)) ?? false,
    ]) {
        for (const section of staffNavigation) {
            const item = section.items.find((candidate) => isVisibleItem(candidate, can) && matcher(candidate.href, candidate.activeFor));
            if (item !== undefined) {
                return item.icon;
            }
        }
    }

    return null;
}

/**
 * Page title banner (UI_UX_DESIGN.md §15): navy-to-green band with gold
 * accents, the section icon, breadcrumbs, description and actions.
 * In the staff area it runs edge to edge across the top of the content area
 * (PageHeaderSlot); elsewhere it is a rounded card in the page. Prints as plain text.
 */
export function PageHeader({ title, description, actions, breadcrumbs }: PageHeaderProps) {
    const Icon = useSectionIcon();
    const slot = useContext(PageHeaderSlot);
    const fullWidth = slot !== null;

    const banner = (
        <div
            className={cn(
                'brand-dark relative overflow-hidden bg-gradient-to-r from-auth-navy via-[#0f3a3f] to-primary-800 text-white print:mb-4 print:rounded-none print:bg-none print:text-ink print:shadow-none',
                fullWidth ? 'shadow-sm' : 'mb-6 rounded-2xl shadow-md',
            )}
        >
            {/* Decorative gold stripes and glow, as on the sign-in page. */}
            <div aria-hidden="true" className="pointer-events-none absolute inset-0 print:hidden">
                <div className="absolute -right-10 top-0 h-full w-40 -skew-x-[30deg] bg-gradient-to-b from-accent-300/25 to-transparent" />
                <div className="absolute right-24 top-0 h-full w-2 -skew-x-[30deg] bg-accent-400/40" />
                <div className="absolute -left-24 -top-24 size-64 rounded-full bg-primary-500/20 blur-3xl" />
            </div>
            <div aria-hidden="true" className="absolute inset-x-0 bottom-0 h-1 bg-gradient-to-r from-accent-400 via-accent-300 to-transparent print:hidden" />

            {/* Edge to edge, the text lines up with the page content below (same padding and width). */}
            <div className={cn('relative', fullWidth ? 'px-4 py-6 sm:px-6 lg:px-8 print:p-0' : 'p-5 sm:p-6 print:p-0')}>
                <div className={cn('flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between', fullWidth && 'mx-auto w-full max-w-7xl')}>
                    <div className="flex min-w-0 items-start gap-4">
                        {Icon !== null && (
                            <span className="hidden size-12 shrink-0 items-center justify-center rounded-xl bg-white/10 text-accent-300 ring-1 ring-accent-300/50 sm:flex print:hidden">
                                <Icon className="size-6" aria-hidden="true" />
                            </span>
                        )}
                        <div className="min-w-0">
                            {breadcrumbs && breadcrumbs.length > 0 && <Breadcrumbs items={breadcrumbs} />}
                            <h1 className="font-serif text-2xl font-bold tracking-tight text-white sm:text-[1.7rem] print:text-ink">{title}</h1>
                            {description && <div className="mt-1.5 max-w-3xl text-sm leading-relaxed text-primary-100 print:text-ink-muted [&_a]:text-accent-200! [&_a]:underline [&_a:hover]:text-white!">{description}</div>}
                        </div>
                    </div>
                    {actions && <div className="flex shrink-0 flex-wrap items-center gap-2 print:hidden">{actions}</div>}
                </div>
            </div>
        </div>
    );

    return fullWidth ? createPortal(banner, slot) : banner;
}

/**
 * The way back to the pages above this one. The current page itself is not
 * repeated: the title right below names it (owner request, 2026-10-03).
 */
function Breadcrumbs({ items }: { items: BreadcrumbItem[] }) {
    const parents = items.slice(0, -1);
    if (parents.length === 0) {
        return null;
    }

    return (
        <nav aria-label="Breadcrumb" className="mb-1.5">
            <ol className="flex flex-wrap items-center gap-1 text-xs font-medium uppercase tracking-[0.12em] text-primary-100 print:text-ink-muted">
                {parents.map((item, index) => (
                    <li key={`${item.label}-${index}`} className="flex items-center gap-1">
                        {item.href ? (
                            <Link href={item.href} className="rounded-sm hover:text-white hover:underline">
                                {item.label}
                            </Link>
                        ) : (
                            <span>{item.label}</span>
                        )}
                        <ChevronRight className="size-3.5 text-primary-200" aria-hidden="true" />
                    </li>
                ))}
            </ol>
        </nav>
    );
}
