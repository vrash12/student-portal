import { CircleAlert, CircleCheck, Info, TriangleAlert, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

type AlertTone = 'info' | 'success' | 'warning' | 'danger';

const toneStyles: Record<AlertTone, { icon: LucideIcon; classes: string }> = {
    info: { icon: Info, classes: 'border-info-border bg-info-bg text-info-fg' },
    success: { icon: CircleCheck, classes: 'border-success-border bg-success-bg text-success-fg' },
    warning: { icon: TriangleAlert, classes: 'border-warning-border bg-warning-bg text-warning-fg' },
    danger: { icon: CircleAlert, classes: 'border-danger-border bg-danger-bg text-danger-fg' },
};

interface AlertProps {
    tone?: AlertTone;
    title?: string;
    children: ReactNode;
    className?: string;
}

/** Inline message explaining what happened and what to do (§49). */
export function Alert({ tone = 'info', title, children, className }: AlertProps) {
    const { icon: Icon, classes } = toneStyles[tone];

    return (
        <div
            role={tone === 'danger' ? 'alert' : 'status'}
            className={cn('flex items-start gap-3 rounded-lg border px-4 py-3', classes, className)}
        >
            <Icon className="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <div className="min-w-0 text-sm">
                {title && <p className="font-semibold">{title}</p>}
                <div className={cn(title && 'mt-0.5', 'text-ink')}>{children}</div>
            </div>
        </div>
    );
}
