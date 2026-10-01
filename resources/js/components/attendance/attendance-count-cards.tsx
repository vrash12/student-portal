import { StatusBadge } from '@/components/ui/status-badge';
import type { AttendanceCounts, AttendanceStatusOption } from '@/types/attendance';

interface AttendanceCountCardsProps {
    /** The statuses in order, with their labels and tones (from the server). */
    options: AttendanceStatusOption[];
    counts: AttendanceCounts;
    /** Shown as a "Not Recorded" card when given. */
    unrecorded?: number;
}

/**
 * Saved attendance counts by status, each labelled with its status badge
 * (text and icon, never colour alone). Render where a <dl> fits.
 */
export function AttendanceCountCards({ options, counts, unrecorded }: AttendanceCountCardsProps) {
    return (
        <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            {options.map((option) => (
                <div key={option.value} className="rounded-lg border border-line bg-surface px-4 py-4">
                    <dt>
                        <StatusBadge tone={option.tone}>{option.label}</StatusBadge>
                    </dt>
                    <dd className="mt-2 text-3xl font-semibold text-ink tabular-nums">{counts[option.value]}</dd>
                </div>
            ))}
            {unrecorded !== undefined && (
                <div className="rounded-lg border border-line bg-surface px-4 py-4">
                    <dt>
                        <StatusBadge tone="neutral">Not Recorded</StatusBadge>
                    </dt>
                    <dd className="mt-2 text-3xl font-semibold text-ink tabular-nums">{unrecorded}</dd>
                </div>
            )}
        </dl>
    );
}
