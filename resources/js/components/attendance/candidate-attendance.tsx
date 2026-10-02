import { CalendarCheck } from 'lucide-react';
import { AttendanceStatusBadge, formatHours } from '@/components/attendance/attendance-status';
import { ChartFigure } from '@/components/charts/chart-figure';
import { PieChart } from '@/components/charts/pie-chart';
import { EmptyState } from '@/components/ui/empty-state';
import { Panel } from '@/components/ui/panel';
import { RowAction } from '@/components/ui/table';
import { formatCalendarDate, formatPercent } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { AttendanceSummary, CandidateAttendanceSession } from '@/types/attendance';
import type { PieSlice } from '@/types/charts';

interface CandidateAttendanceProps {
    /** AttendanceLedger::summariesFor for the candidate's current class. */
    summary: AttendanceSummary;
    /** AttendanceLedger::candidateHistory, newest first (usually limited). */
    sessions: CandidateAttendanceSession[];
    /** Staff who keep the class's attendance may open each session's roll call. */
    linkSessions?: boolean;
    description?: string;
}

/**
 * A candidate's attendance: the rate, attended hours and counts calculated
 * by the server, then the latest sessions with the candidate's status.
 * Usable on the candidate profile and in the candidate portal.
 */
export function CandidateAttendance({
    summary,
    sessions,
    linkSessions = false,
    description = 'Training sessions of the current class. Excused and unrecorded sessions are left out of the rate.',
}: CandidateAttendanceProps) {
    // Every session of the class by the candidate's status, with "Not recorded" for the rest.
    const slices: PieSlice[] = [
        { label: 'Present', value: summary.present, tone: 'passing' },
        { label: 'Late', value: summary.late, tone: 'atRisk' },
        { label: 'Excused', value: summary.excused, tone: 'incomplete' },
        { label: 'Absent', value: summary.absent, tone: 'failing' },
        { label: 'Not recorded', value: summary.unrecorded, tone: 'none' },
    ];

    return (
        <Panel title="Attendance" description={description} bodyClassName={summary.sessions === 0 ? undefined : 'p-0'}>
            {summary.sessions === 0 ? (
                <EmptyState icon={CalendarCheck} headingLevel="h3" title="No sessions yet" description="Training sessions of the class appear here once they are recorded." />
            ) : (
                <div className="flex flex-col">
                    <dl className="grid grid-cols-2 gap-4 px-5 py-4 sm:grid-cols-3">
                        <div>
                            <dt className="text-sm font-medium text-ink-muted">Attendance rate</dt>
                            <dd className="mt-1 text-2xl font-semibold text-ink tabular-nums">{formatPercent(summary.rate)}</dd>
                        </div>
                        <div>
                            <dt className="text-sm font-medium text-ink-muted">Hours attended</dt>
                            <dd className="mt-1 text-2xl font-semibold text-ink tabular-nums">{formatHours(summary.hours)}</dd>
                        </div>
                        <div>
                            <dt className="text-sm font-medium text-ink-muted">Sessions</dt>
                            <dd className="mt-1 text-2xl font-semibold text-ink tabular-nums">{summary.sessions}</dd>
                        </div>
                    </dl>

                    <div className="px-5 pb-5">
                        <ChartFigure title="Sessions by Status" description="Every session of the class by the candidate's status.">
                            <PieChart slices={slices} noun={{ one: 'session', other: 'sessions' }} listEmpty />
                        </ChartFigure>
                    </div>

                    {sessions.length > 0 && (
                        <div className="border-t border-line">
                            <h3 className="px-5 pt-4 text-sm font-semibold text-ink">Latest Sessions</h3>
                            <ul className="divide-y divide-line">
                                {sessions.map((session) => (
                                    <li key={session.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                                        <div className="min-w-0">
                                            <p className="font-medium text-ink">{session.title}</p>
                                            <p className="text-sm text-ink-muted">
                                                {formatCalendarDate(session.heldOn)} · {formatHours(session.hours)}
                                            </p>
                                            {session.remarks !== null && <p className="text-sm text-ink-muted">Remarks: {session.remarks}</p>}
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <AttendanceStatusBadge status={session.status} />
                                            {linkSessions && (
                                                <RowAction href={routes.attendance.sessions.show(session.id)} label={`Open the roll call of ${session.title}`}>
                                                    Roll Call
                                                </RowAction>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            )}
        </Panel>
    );
}
