import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { buttonClasses } from '@/components/ui/button';
import type { Paginated } from '@/types';

interface PaginationProps {
    page: Paginated<unknown>;
    /** Nouns for the summary, e.g. { one: 'account', other: 'accounts' }. */
    noun: { one: string; other: string };
}

/**
 * Server-side pagination summary and navigation (UI_UX_DESIGN.md §53).
 */
export function Pagination({ page, noun }: PaginationProps) {
    if (page.total === 0) {
        return null;
    }

    return (
        <nav
            aria-label="Pagination"
            className="flex flex-col items-center justify-between gap-3 border-t border-line px-4 py-3 sm:flex-row"
        >
            <p className="text-sm text-ink-muted">
                Showing <span className="font-medium text-ink tabular-nums">{page.from}</span>–
                <span className="font-medium text-ink tabular-nums">{page.to}</span> of{' '}
                <span className="font-medium text-ink tabular-nums">{page.total}</span>{' '}
                {page.total === 1 ? noun.one : noun.other}
            </p>
            {page.last_page > 1 && (
                <div className="flex items-center gap-2">
                    <PageLink href={page.prev_page_url} label="Previous page">
                        <ChevronLeft className="size-4" aria-hidden="true" />
                        Previous
                    </PageLink>
                    <span className="px-1 text-sm text-ink-muted tabular-nums">
                        Page {page.current_page} of {page.last_page}
                    </span>
                    <PageLink href={page.next_page_url} label="Next page">
                        Next
                        <ChevronRight className="size-4" aria-hidden="true" />
                    </PageLink>
                </div>
            )}
        </nav>
    );
}

function PageLink({ href, label, children }: { href: string | null; label: string; children: ReactNode }) {
    const classes = buttonClasses('secondary', 'sm');

    if (href === null) {
        return (
            <span className={`${classes} cursor-not-allowed opacity-50`} aria-disabled="true" aria-label={label}>
                {children}
            </span>
        );
    }

    return (
        <Link href={href} className={classes} aria-label={label} preserveScroll>
            {children}
        </Link>
    );
}
