import type { ConductTotals } from '@/types/conduct';

/** Net points with an explicit sign: "+5", "−3" (minus sign) or "0". */
export function formatNetPoints(net: number): string {
    if (net > 0) {
        return `+${net}`;
    }

    return net < 0 ? `−${Math.abs(net)}` : '0';
}

/** "1 point" / "5 points". */
export function formatPoints(points: number): string {
    return points === 1 ? '1 point' : `${points} points`;
}

interface ConductTotalsListProps {
    totals: ConductTotals;
}

/**
 * Merit, demerit and net points calculated by the server (ConductLedger),
 * styled like MetricCard (UI_UX_DESIGN.md §17). Neutral by design: the
 * labels carry the meaning, not colour.
 */
export function ConductTotalsList({ totals }: ConductTotalsListProps) {
    return (
        <dl className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <TotalCard label="Merits" value={String(totals.merits)} description="Points from merits that count." />
            <TotalCard label="Demerits" value={String(totals.demerits)} description="Points from demerits that count." />
            <TotalCard label="Net" value={formatNetPoints(totals.net)} description="Merits minus demerits. Voided entries are left out." />
        </dl>
    );
}

function TotalCard({ label, value, description }: { label: string; value: string; description: string }) {
    return (
        <div className="rounded-lg border border-line-box bg-surface px-4 py-4">
            <dt className="text-sm font-medium text-ink-muted">{label}</dt>
            <dd className="mt-2 text-3xl font-semibold text-ink tabular-nums">{value}</dd>
            <dd className="mt-1 text-sm text-ink-subtle">{description}</dd>
        </div>
    );
}
