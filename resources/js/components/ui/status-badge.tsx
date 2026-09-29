import { CircleCheck, CircleMinus, CircleX, Info, TriangleAlert, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

export type StatusTone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

const toneClasses: Record<StatusTone, string> = {
    success: 'border-success-border bg-success-bg text-success-fg',
    warning: 'border-warning-border bg-warning-bg text-warning-fg',
    danger: 'border-danger-border bg-danger-bg text-danger-fg',
    info: 'border-info-border bg-info-bg text-info-fg',
    neutral: 'border-neutral-border bg-neutral-bg text-neutral-fg',
};

const toneIcons: Record<StatusTone, LucideIcon> = {
    success: CircleCheck,
    warning: TriangleAlert,
    danger: CircleX,
    info: Info,
    neutral: CircleMinus,
};

interface StatusBadgeProps {
    tone: StatusTone;
    /** Visible text is required: status is never shown by color alone (§47). */
    children: ReactNode;
    className?: string;
}

export function StatusBadge({ tone, children, className }: StatusBadgeProps) {
    const Icon = toneIcons[tone];

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 whitespace-nowrap rounded-md border px-2 py-0.5 text-xs font-medium',
                toneClasses[tone],
                className,
            )}
        >
            <Icon className="size-3.5 shrink-0" aria-hidden="true" />
            {children}
        </span>
    );
}
