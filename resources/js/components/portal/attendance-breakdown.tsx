import { CalendarCheck, Clock3, ListChecks, Percent } from 'lucide-react';
import { AttendanceStatusBadge, formatHours } from '@/components/attendance/attendance-status';
import { AttendanceStatusPie } from '@/components/attendance/attendance-trend';
import { PortalEmpty, StatTile } from '@/components/portal/portal-ui';
import { formatCalendarDate, formatPercent } from '@/lib/format';
import type { AttendanceSummary, CandidateAttendanceSession } from '@/types/attendance';

/**
 * The candidate's own attendance in the portal: rate, hours and sessions,
 * a ring of the sessions by status with the counts in words, and the latest
 * sessions. Every figure comes from the server (AttendanceLedger).
 */
export function AttendanceBreakdown({ summary, sessions }: { summary: AttendanceSummary; sessions: CandidateAttendanceSession[] }) {
    if (summary.sessions === 0) {
        return (
            <PortalEmpty icon={CalendarCheck} title="No training sessions yet">
                Sessions of your class appear here once attendance is recorded.
            </PortalEmpty>
        );
    }

    return (
        <div className="flex flex-col gap-8">
            <dl className="grid gap-5 sm:grid-cols-3">
                <StatTile icon={Percent} label="Attendance Rate" value={formatPercent(summary.rate)} hint="Excused sessions are not counted." />
                <StatTile icon={Clock3} label="Hours Attended" value={formatHours(summary.hours)} />
                <StatTile icon={ListChecks} label="Sessions" value={summary.sessions} hint={summary.unrecorded > 0 ? `${summary.unrecorded} not recorded yet` : undefined} />
            </dl>

            <figure className="flex flex-col gap-3 [-webkit-print-color-adjust:exact] [print-color-adjust:exact]">
                <figcaption className="text-sm font-semibold text-ink">Sessions by Status</figcaption>
                <AttendanceStatusPie counts={summary} noun={{ one: 'session', other: 'sessions' }} />
            </figure>

            {sessions.length > 0 && (
                <div>
                    <h3 className="mb-3 text-base font-semibold text-ink">Latest Sessions</h3>
                    <ul className="divide-y divide-line rounded-xl border border-line-box">
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
