import { Link, usePage } from '@inertiajs/react';
import { ChevronRight, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
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
 * The section of the staff navigation the current page belongs to (its
 * sidebar item and group), used for the header's icon and eyebrow.
 */
function useSection(): { icon: LucideIcon | null; group: string | null; item: string | null } {
    const { url } = usePage();
    const { can } = usePermissions();
    for (const matcher of [
        (href: string) => isActivePath(url, href),
        (_href: string, activeFor?: string[]) => activeFor?.some((prefix) => isActivePath(url, prefix)) ?? false,
    ]) {
        for (const section of staffNavigation) {
            const item = section.items.find((candidate) => isVisibleItem(candidate, can) && matcher(candidate.href, candidate.activeFor));
            if (item !== undefined) {
                return { icon: item.icon, group: section.label, item: item.label };
            }
        }
    }

    return { icon: null, group: null, item: null };
}

/**
 * Page title banner (UI_UX_DESIGN.md §15): navy-to-green band with gold
 * accents, the section icon and name, breadcrumbs, description and actions.
 * Prints as plain text.
 */
export function PageHeader({ title, description, actions, breadcrumbs }: PageHeaderProps) {
    const section = useSection();
    const Icon = section.icon;
    const eyebrow = [section.group, section.item].filter((part): part is string => part !== null && part !== title).join(' · ');

    return (
        <div className="brand-dark relative mb-6 overflow-hidden rounded-2xl bg-gradient-to-r from-auth-navy via-[#0f3a3f] to-primary-800 text-white shadow-md print:mb-4 print:rounded-none print:bg-none print:text-ink print:shadow-none">
            {/* Decorative gold stripes and glow, as on the sign-in page. */}
            <div aria-hidden="true" className="pointer-events-none absolute inset-0 print:hidden">
                <div className="absolute -right-10 top-0 h-full w-40 -skew-x-[30deg] bg-gradient-to-b from-accent-300/25 to-transparent" />
                <div className="absolute right-24 top-0 h-full w-2 -skew-x-[30deg] bg-accent-400/40" />
                <div className="absolute -left-24 -top-24 size-64 rounded-full bg-primary-500/20 blur-3xl" />
            </div>
            <div aria-hidden="true" className="absolute inset-x-0 bottom-0 h-1 bg-gradient-to-r from-accent-400 via-accent-300 to-transparent print:hidden" />

            <div className="relative flex flex-col gap-4 p-5 sm:p-6 xl:flex-row xl:items-end xl:justify-between print:p-0">
                <div className="flex min-w-0 items-start gap-4">
                    {Icon !== null && (
                        <span className="hidden size-12 shrink-0 items-center justify-center rounded-xl bg-white/10 text-accent-300 ring-1 ring-accent-300/50 sm:flex print:hidden">
                            <Icon className="size-6" aria-hidden="true" />
                        </span>
                    )}
                    <div className="min-w-0">
                        {breadcrumbs && breadcrumbs.length > 0 ? (
                            <Breadcrumbs items={breadcrumbs} />
                        ) : (
                            eyebrow !== '' && <p className="mb-1 text-xs font-semibold uppercase tracking-[0.18em] text-accent-300 print:text-ink-muted">{eyebrow}</p>
                        )}
                        <h1 className="font-serif text-2xl font-bold tracking-tight text-white sm:text-[1.7rem] print:text-ink">{title}</h1>
                        {description && <div className="mt-1.5 max-w-3xl text-sm leading-relaxed text-primary-100 print:text-ink-muted [&_a]:text-accent-200! [&_a]:underline [&_a:hover]:text-white!">{description}</div>}
                    </div>
                </div>
                {actions && <div className="flex shrink-0 flex-wrap items-center gap-2 print:hidden">{actions}</div>}
            </div>
        </div>
    );
}

function Breadcrumbs({ items }: { items: BreadcrumbItem[] }) {
    return (
        <nav aria-label="Breadcrumb" className="mb-1.5">
            <ol className="flex flex-wrap items-center gap-1 text-xs font-medium uppercase tracking-[0.12em] text-accent-300 print:text-ink-muted">
                {items.map((item, index) => {
                    const isLast = index === items.length - 1;

                    return (
                        <li key={`${item.label}-${index}`} className="flex items-center gap-1">
                            {item.href && !isLast ? (
                                <Link href={item.href} className="rounded-sm text-primary-100 hover:text-white hover:underline">
                                    {item.label}
                                </Link>
                            ) : (
                                <span aria-current={isLast ? 'page' : undefined} className={isLast ? 'text-accent-300' : undefined}>
                                    {item.label}
                                </span>
                            )}
                            {!isLast && <ChevronRight className="size-3.5 text-primary-200" aria-hidden="true" />}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
