import { ChevronLeft, ChevronRight, CircleAlert } from 'lucide-react';
import { Fragment, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/cn';

export interface ClientPage<T> {
    /** Rows of the current page. */
    rows: T[];
    page: number;
    pages: number;
    /** 1-based position of the first and last row shown (0 when empty). */
    from: number;
    to: number;
    total: number;
    setPage: (page: number) => void;
}

/**
 * Pages a list the server already sent in full (e.g. a live monitoring
 * snapshot). The current page is clamped when the list shrinks on refresh.
 */
export function useClientPagination<T>(items: readonly T[], pageSize = 10): ClientPage<T> {
    const [requestedPage, setPage] = useState(1);
    const total = items.length;
    const pages = Math.max(1, Math.ceil(total / pageSize));
    const page = Math.min(Math.max(1, requestedPage), pages);
    const start = (page - 1) * pageSize;
    const rows = items.slice(start, start + pageSize);

    return { rows, page, pages, total, from: total === 0 ? 0 : start + 1, to: start + rows.length, setPage };
}

interface ClientPaginationProps {
    pagination: ClientPage<unknown>;
    /** Nouns for the summary, e.g. { one: 'candidate', other: 'candidates' }. */
    noun: { one: string; other: string };
    /** Accessible name of the navigation landmark, e.g. "Participation pages". */
    label?: string;
}

/**
 * Client-side counterpart of <Pagination> with the same summary and
 * "Page x of y" navigation (UI_UX_DESIGN.md §53). Buttons are type="button"
 * so it is safe inside forms.
 */
export function ClientPagination({ pagination, noun, label = 'Pagination' }: ClientPaginationProps) {
    const { page, pages, from, to, total, setPage } = pagination;

    if (total === 0) {
        return null;
    }

    return (
        <nav aria-label={label} className="flex flex-col items-center justify-between gap-3 border-t border-line px-4 py-3 sm:flex-row">
            <p className="text-sm text-ink-muted">
                Showing <span className="font-medium text-ink tabular-nums">{from}</span>–<span className="font-medium text-ink tabular-nums">{to}</span> of{' '}
                <span className="font-medium text-ink tabular-nums">{total}</span> {total === 1 ? noun.one : noun.other}
            </p>
            {pages > 1 && (
                <div className="flex items-center gap-2">
                    <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)} aria-label="Previous page">
                        <ChevronLeft className="size-4" aria-hidden="true" />
                        Previous
                    </Button>
                    <span className="px-1 text-sm text-ink-muted tabular-nums" aria-live="polite">
                        Page {page} of {pages}
                    </span>
                    <Button variant="secondary" size="sm" disabled={page >= pages} onClick={() => setPage(page + 1)} aria-label="Next page">
                        Next
                        <ChevronRight className="size-4" aria-hidden="true" />
                    </Button>
                </div>
            )}
        </nav>
    );
}

/** The 1-based pages that hold at least one matching item, in order. */
export function pagesWhere<T>(items: readonly T[], matches: (item: T) => boolean, pageSize = 10): number[] {
    const pages = new Set<number>();
    items.forEach((item, index) => {
        if (matches(item)) {
            pages.add(Math.floor(index / pageSize) + 1);
        }
    });

    return [...pages];
}

/**
 * For data-entry grids that are paged on screen but saved as a whole: when a
 * new set of validation errors arrives (a new `errors` object), shows the
 * first page that holds an error. `errorPages` comes from pagesWhere().
 */
export function useShowFirstErrorPage(errors: object, errorPages: number[], setPage: (page: number) => void): void {
    useEffect(() => {
        const [firstPage] = errorPages;
        if (firstPage !== undefined) {
            setPage(firstPage);
        }
        // Only a new response (a new errors object) moves the page; paging or typing afterwards must not jump back.
    }, [errors]);
}

interface OtherPageErrorsProps {
    pagination: ClientPage<unknown>;
    /** Pages holding rows with errors, from pagesWhere(). */
    errorPages: number[];
    label?: string;
    tone?: 'danger' | 'warning';
}

/** "Errors on other pages: page 2, 3", with each page number going to that page. Hidden when there are none. */
export function OtherPageErrors({ pagination, errorPages, label = 'Errors on other pages', tone = 'danger' }: OtherPageErrorsProps) {
    const otherPages = errorPages.filter((page) => page !== pagination.page);

    if (otherPages.length === 0) {
        return null;
    }

    return (
        <p
            className={cn(
                'flex flex-wrap items-center gap-x-1.5 gap-y-1 border-t border-line px-4 py-2 text-sm',
                tone === 'danger' ? 'bg-danger-bg text-danger-fg' : 'bg-warning-bg text-warning-fg',
            )}
        >
            <CircleAlert className="size-4 shrink-0" aria-hidden="true" />
            <span>
                {label}: {otherPages.length === 1 ? 'page' : 'pages'}
            </span>
            {otherPages.map((page, index) => (
                <Fragment key={page}>
                    <button
                        type="button"
                        onClick={() => pagination.setPage(page)}
                        aria-label={`${label}: go to page ${page}`}
                        className="font-semibold underline underline-offset-2 hover:no-underline"
                    >
                        {page}
                    </button>
                    {index < otherPages.length - 1 && <span aria-hidden="true">,</span>}
                </Fragment>
            ))}
        </p>
    );
}
