import { StatusBadge } from '@/components/ui/status-badge';
import { formatGrade } from '@/lib/format';
import type { AreaStatus, QualificationStatus } from '@/types/performance';

/** An area's status as a badge: always text with its tone, never colour alone. */
export function AreaStatusBadge({ status }: { status: AreaStatus }) {
    return <StatusBadge tone={status.tone}>{status.label}</StatusBadge>;
}

/** Qualified / Not Qualified / Pending as a badge. */
export function QualificationBadge({ status, className }: { status: QualificationStatus; className?: string }) {
    return (
        <StatusBadge tone={status.tone} className={className}>
            {status.label}
        </StatusBadge>
    );
}

/** "75.00", or a dash without a grade. Formatting only: grades come from the server. */
export function formatAreaGrade(grade: number | null): string {
    return formatGrade(grade);
}

/** "Weight 40 · pass 75" for an area heading; values as configured, without trailing zeros. */
export function areaRuleLabel(area: { weight: number; passingGrade: number; mustPass: boolean }): string {
    const weight = `Weight ${formatNumber(area.weight)}`;
    const passing = `pass ${formatNumber(area.passingGrade)}`;

    return [weight, passing, area.mustPass ? 'must pass' : null].filter((part): part is string => part !== null).join(' · ');
}

/** 40 → "40", 77.5 → "77.5", 33.33 → "33.33". */
export function formatNumber(value: number): string {
    return Number.isInteger(value) ? String(value) : String(Number(value.toFixed(2)));
}
