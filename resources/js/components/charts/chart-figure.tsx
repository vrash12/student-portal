import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface ChartFigureProps {
    /** Visible caption; also the figure's accessible name. */
    title: string;
    description?: ReactNode;
    children: ReactNode;
    className?: string;
}

/**
 * A titled chart (UI_UX_DESIGN.md §61). Charts here are plain HTML bars, so
 * they need no chart library, work without internet access, and print with
 * their colors.
 */
export function ChartFigure({ title, description, children, className }: ChartFigureProps) {
    return (
        <figure className={cn('flex min-w-0 flex-col gap-3 [-webkit-print-color-adjust:exact] [print-color-adjust:exact] print:break-inside-avoid', className)}>
            <figcaption>
                <span className="block text-sm font-semibold text-ink">{title}</span>
                {description && <span className="mt-0.5 block text-sm text-ink-muted">{description}</span>}
            </figcaption>
            {children}
        </figure>
    );
}
