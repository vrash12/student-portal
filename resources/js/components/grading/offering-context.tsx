import type { BreadcrumbItem } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status-badge';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import type { OfferingContext } from '@/types/grading';

/**
 * Breadcrumbs for instructor grading pages:
 * My Classes / Class / Subject (/ current page).
 */
export function gradebookBreadcrumbs(offering: OfferingContext, current?: string): BreadcrumbItem[] {
    const items: BreadcrumbItem[] = [
        { label: `My ${terms.classBatch.plural}`, href: routes.teaching.classes.index() },
        { label: offering.classBatch.name, href: routes.teaching.classes.show(offering.classBatch.id) },
        { label: offering.subject.name, href: routes.teaching.gradebook(offering.classBatch.id, offering.id) },
    ];

    return current === undefined ? items : [...items, { label: current }];
}

/** "Sample Batch A · Academic Period 2026-1 [Active]" */
export function OfferingDescription({ offering }: { offering: OfferingContext }) {
    return (
        <>
            {offering.classBatch.name} · {offering.period.name}{' '}
            {offering.period.isActive && <StatusBadge tone="success">Active</StatusBadge>}
        </>
    );
}

/** Grading categories and their weights, e.g. "Quizzes 20%". */
export function WeightSummary({ categories }: { categories: Array<{ name: string; weight: string }> }) {
    return (
        <ul className="flex flex-wrap gap-2">
            {categories.map((category) => (
                <li
                    key={category.name}
                    className="inline-flex items-center gap-2 rounded-md border border-line bg-surface-muted px-3 py-1 text-sm text-ink"
                >
                    {category.name}
                    <span className="font-semibold tabular-nums">{category.weight}%</span>
                </li>
            ))}
        </ul>
    );
}
