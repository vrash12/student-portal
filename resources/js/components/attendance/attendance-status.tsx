import { CircleCheck, CircleX, Clock, FileCheck, type LucideIcon } from 'lucide-react';
import { StatusBadge } from '@/components/ui/status-badge';
import type { AttendanceStatusValue } from '@/types/attendance';
import type { StatusValue } from '@/types/grading';

/** One icon per status, so a choice is recognizable without its colour. */
export const attendanceStatusIcons: Record<AttendanceStatusValue, LucideIcon> = {
    present: CircleCheck,
    late: Clock,
    excused: FileCheck,
    absent: CircleX,
};

const hoursFormat = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });

/** "1 hour", "1.5 hours", "0.75 hours". Formatting only: hours come from the server. */
export function formatHours(hours: number): string {
    return `${hoursFormat.format(hours)} ${hours === 1 ? 'hour' : 'hours'}`;
}

/** A saved attendance status as text with its tone, or "Not Recorded". */
export function AttendanceStatusBadge({ status }: { status: StatusValue | null }) {
    return status === null ? <StatusBadge tone="neutral">Not Recorded</StatusBadge> : <StatusBadge tone={status.tone}>{status.label}</StatusBadge>;
}
