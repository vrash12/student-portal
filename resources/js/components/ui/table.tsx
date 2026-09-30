import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

/**
 * Administrative table primitives (UI_UX_DESIGN.md §30–31). Wide tables
 * scroll horizontally on narrow screens instead of squeezing columns.
 */
export function Table({ caption, children, className }: { caption: string; children: ReactNode; className?: string }) {
    return (
        <div className="overflow-x-auto">
            <table className={cn('w-full min-w-[40rem] text-left text-sm', className)}>
                <caption className="sr-only">{caption}</caption>
                {children}
            </table>
        </div>
    );
}

export function TableHead({ children }: { children: ReactNode }) {
    return (
        <thead className="border-b border-primary-200 bg-primary-50 text-xs font-semibold uppercase tracking-wide text-primary-800">
            <tr>{children}</tr>
        </thead>
    );
}

type Align = 'left' | 'right' | 'center';

const alignClasses: Record<Align, string> = {
    left: 'text-left',
    right: 'text-right',
    center: 'text-center',
};

export function Th({ children, align = 'left', className }: { children: ReactNode; align?: Align; className?: string }) {
    return (
        <th scope="col" className={cn('px-4 py-3', alignClasses[align], className)}>
            {children}
        </th>
    );
}

export function TableBody({ children }: { children: ReactNode }) {
    return <tbody className="divide-y divide-line">{children}</tbody>;
}

export function Tr({ children }: { children: ReactNode }) {
    return <tr className="hover:bg-surface-muted">{children}</tr>;
}

export function Td({
    children,
    align = 'left',
    numeric = false,
    className,
}: {
    children: ReactNode;
    align?: Align;
    /** Tabular figures so numbers line up vertically (§31). */
    numeric?: boolean;
    className?: string;
}) {
    return <td className={cn('px-4 py-3', alignClasses[align], numeric && 'tabular-nums', className)}>{children}</td>;
}

/** Row-level action link, e.g. "View" or "Edit", with an accessible label. */
export function RowAction({ href, label, children }: { href: string; label: string; children: ReactNode }) {
    return (
        <Link
            href={href}
            aria-label={label}
            className="inline-flex h-9 items-center whitespace-nowrap rounded-md px-3 font-medium text-primary-700 hover:bg-primary-50 pointer-coarse:h-11"
        >
            {children}
        </Link>
    );
}
