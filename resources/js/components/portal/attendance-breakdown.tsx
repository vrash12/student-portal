import { CalendarCheck, Clock3, ListChecks, Percent } from 'lucide-react';
import { AttendanceStatusBadge, formatHours } from '@/components/attendance/attendance-status';
import { PortalEmpty, StatTile } from '@/components/portal/portal-ui';
import { cn } from '@/lib/cn';
import { formatCalendarDate, formatPercent } from '@/lib/format';
import type { AttendanceSummary, CandidateAttendanceSession } from '@/types/attendance';

/** Session statuses in bar order, with their chart fills (always shown with the label and count). */
const PARTS: ReadonlyArray<{ key: 'present' | 'late' | 'excused' | 'absent'; label: string; fill: string }> = [
    { key: 'present', label: 'Present', fill: 'bg-chart-passing' },
    { key: 'late', label: 'Late', fill: 'bg-chart-at-risk' },
    { key: 'excused', label: 'Excused', fill: 'bg-chart-incomplete' },
    { key: 'absent', label: 'Absent', fill: 'bg-chart-failing' },
];

/**
 * The candidate's own attendance in the portal: rate, hours and sessions,
 * a bar split by status with the counts in words, and the latest sessions.
 * Every figure comes from the server (AttendanceLedger).
 */
export function AttendanceBreakdown({ summary, sessions }: { summary: AttendanceSummary; sessions: CandidateAttendanceSession[] }) {
    if (summary.sessions === 0) {
        return (
            <PortalEmpty icon={CalendarCheck} title="No training sessions yet">
                Sessions of your class appear here once attendance is recorded.
            </PortalEmpty>
        );
    }

    const counted = PARTS.filter((part) => summary[part.key] > 0);

    return (
        <div className="flex flex-col gap-8">
            <dl className="grid gap-5 sm:grid-cols-3">
                <StatTile icon={Percent} label="Attendance Rate" value={formatPercent(summary.rate)} hint="Excused sessions are not counted." />
                <StatTile icon={Clock3} label="Hours Attended" value={formatHours(summary.hours)} />
                <StatTile icon={ListChecks} label="Sessions" value={summary.sessions} hint={summary.unrecorded > 0 ? `${summary.unrecorded} not recorded yet` : undefined} />
            </dl>

            <figure className="flex flex-col gap-3 [-webkit-print-color-adjust:exact] [print-color-adjust:exact]">
                <figcaption className="text-sm font-semibold text-ink">Sessions by Status</figcaption>
                <div aria-hidden="true" className="flex h-5 w-full gap-0.5 overflow-hidden rounded-md bg-chart-track">
                    {counted.map((part) => (
                        <div key={part.key} className={cn('min-w-1 basis-0', part.fill)} style={{ flexGrow: summary[part.key] }} />
                    ))}
                </div>
                <ul className="flex flex-wrap gap-x-6 gap-y-2 text-sm text-ink">
                    {PARTS.map((part) => (
                        <li key={part.key} className="flex items-center gap-2">
                            <span aria-hidden="true" className={cn('size-3 rounded-sm', part.fill)} />
                            {part.label} <span className="font-semibold tabular-nums">{summary[part.key]}</span>
                        </li>
                    ))}
                </ul>
            </figure>

            {sessions.length > 0 && (
                <div>
                    <h3 className="mb-3 text-base font-semibold text-ink">Latest Sessions</h3>
                    <ul className="divide-y divide-line rounded-xl border border-line">
                        {sessions.map((session) => (
                            <li key={session.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                                <div className="min-w-0">
                                    <p className="text-base font-medium text-ink">{session.title}</p>
                                    <p className="text-sm text-ink-muted">
                                        {formatCalendarDate(session.heldOn)} · {formatHours(session.hours)}
                                    </p>
                                </div>
                                <AttendanceStatusBadge status={session.status} />
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
