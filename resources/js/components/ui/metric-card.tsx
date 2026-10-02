import type { ReactNode } from 'react';

interface MetricCardProps {
    label: string;
    value: number;
    /** Optional supporting line, e.g. the period the number refers to. */
    description?: ReactNode;
}

/**
 * One primary number with one clear label (UI_UX_DESIGN.md §17). Neutral by
 * design: status colors are reserved for statuses, not for decoration (§5).
 * Render inside a <dl> so label and value are announced together.
 */
export function MetricCard({ label, value, description }: MetricCardProps) {
    return (
        <div className="rounded-lg border border-line-box bg-surface px-4 py-4">
            <dt className="text-sm font-medium text-ink-muted">{label}</dt>
            <dd className="mt-2 text-3xl font-semibold text-ink tabular-nums">{value}</dd>
            {description && <dd className="mt-1 text-sm text-ink-subtle">{description}</dd>}
        </div>
    );
}
