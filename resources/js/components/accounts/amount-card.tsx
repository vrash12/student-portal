import type { ReactNode } from 'react';

interface AmountCardProps {
    label: string;
    /** Already formatted by useMoney. */
    amount: string;
    description?: ReactNode;
}

/**
 * One money figure with its label, styled like MetricCard (UI_UX_DESIGN.md
 * §17), which only takes whole numbers. Render inside a <dl>.
 */
export function AmountCard({ label, amount, description }: AmountCardProps) {
    return (
        <div className="rounded-lg border border-line bg-surface px-4 py-4">
            <dt className="text-sm font-medium text-ink-muted">{label}</dt>
            <dd className="mt-2 text-2xl font-semibold text-ink tabular-nums sm:text-3xl">{amount}</dd>
            {description && <dd className="mt-1 text-sm text-ink-subtle">{description}</dd>}
        </div>
    );
}
