import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';

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

export function PageHeader({ title, description, actions, breadcrumbs }: PageHeaderProps) {
    return (
        <div className="mb-6 flex flex-col gap-4 rounded-xl border border-line border-l-4 border-l-primary-600 bg-surface p-5 shadow-sm xl:flex-row xl:items-end xl:justify-between print:border-0 print:p-0 print:shadow-none">
            <div className="min-w-0">
                {breadcrumbs && breadcrumbs.length > 0 && <Breadcrumbs items={breadcrumbs} />}
                <h1 className="text-2xl font-bold tracking-tight text-primary-900">{title}</h1>
                {description && <p className="mt-1 text-sm text-ink-muted">{description}</p>}
            </div>
            {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}

function Breadcrumbs({ items }: { items: BreadcrumbItem[] }) {
    return (
        <nav aria-label="Breadcrumb" className="mb-2">
            <ol className="flex flex-wrap items-center gap-1 text-sm text-ink-muted">
                {items.map((item, index) => {
                    const isLast = index === items.length - 1;

                    return (
                        <li key={`${item.label}-${index}`} className="flex items-center gap-1">
                            {item.href && !isLast ? (
                                <Link href={item.href} className="rounded-sm hover:text-ink hover:underline">
                                    {item.label}
                                </Link>
                            ) : (
                                <span aria-current={isLast ? 'page' : undefined} className={isLast ? 'text-ink' : undefined}>
                                    {item.label}
                                </span>
                            )}
                            {!isLast && <ChevronRight className="size-3.5" aria-hidden="true" />}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
