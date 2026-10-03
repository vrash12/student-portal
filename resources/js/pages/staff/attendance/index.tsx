import { Head } from '@inertiajs/react';
import { CalendarCheck, Plus } from 'lucide-react';
import { CampusFilter } from '@/components/academic/campus-filter';
import { formatHours } from '@/components/attendance/attendance-status';
import { AttendanceTrendCharts, hasAttendance } from '@/components/attendance/attendance-trend';
import { ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar } from '@/components/ui/filter-bar';
import { FormField, SelectInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { Panel } from '@/components/ui/panel';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatCalendarDate } from '@/lib/format';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import { withQuery } from '@/lib/url';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { CampusOption, Paginated } from '@/types';
import type { AttendanceSessionListItem, AttendanceTrend } from '@/types/attendance';

interface AttendanceIndexProps {
    sessions: Paginated<AttendanceSessionListItem>;
    /** Every session of the filtered classes (not only this page), for the charts. */
    trend: AttendanceTrend;
    filters: { period: string; campus: string; class: string };
    /** Campuses to filter by (accounts that see every campus only). */
    campusOptions: CampusOption[];
    /** Periods with classes the user keeps attendance for, active first. */
    periods: Array<{ id: number; name: string; isActive: boolean }>;
    /** Those classes in the selected period. */
    classes: Array<{ id: number; name: string }>;
    /** "all": every class; "taught": only the classes the user teaches. */
    scope: 'all' | 'taught';
    can: { create: boolean };
}

export default function AttendanceIndex({ sessions, trend, filters, campusOptions, periods, classes, scope, can }: AttendanceIndexProps) {
    const { singular, plural } = terms.classBatch;
    const defaultPeriod = String(periods[0]?.id ?? '');
    const { values, update, updateMany } = useQueryFilters(routes.attendance.index(), { period: filters.period, campus: filters.campus, class: filters.class });
    const canReset = values.period !== defaultPeriod || values.campus !== '' || values.class !== '';
    // Pre-selects the filtered class on the new session form.
    const createHref = withQuery(routes.attendance.sessions.create(), { class: values.class });

    const newSessionAction = can.create && (
        <ButtonLink href={createHref} variant="primary" icon={<Plus className="size-4" aria-hidden="true" />}>
            New Session
        </ButtonLink>
    );

    return (
        <>
            <Head title="Attendance" />

            <PageHeader
                title="Attendance"
                description={
                    scope === 'all'
                        ? `Training sessions of every ${singular.toLowerCase()}. Open a session to take the roll call.`
                        : `Training sessions of the ${plural.toLowerCase()} you teach. Open a session to take the roll call.`
                }
                actions={sessions.data.length > 0 && newSessionAction}
            />

            {hasAttendance(trend) && (
                <Panel
                    title="At a Glance"
                    description={`Attendance of ${filters.class === '' ? `every ${singular.toLowerCase()} listed` : `the selected ${singular.toLowerCase()}`} in the selected period, from all of its sessions (not only this page).`}
                    className="mb-6"
                >
                    <AttendanceTrendCharts trend={trend} />
                </Panel>
            )}

            <section className="rounded-lg border border-line-box bg-surface" aria-label="Training sessions">
                <FilterBar onReset={() => updateMany({ period: defaultPeriod, campus: '', class: '' })} canReset={canReset}>
                    <FormField label="Academic period" className="sm:w-60">
                        <SelectInput value={values.period} onChange={(event) => updateMany({ period: event.target.value, class: '' })}>
                            {periods.length === 0 && <option value="">No academic periods</option>}
                            {periods.map((period) => (
                                <option key={period.id} value={String(period.id)}>
                                    {period.name}
                                    {period.isActive ? ' (active)' : ''}
                                </option>
                            ))}
                        </SelectInput>
                    </FormField>
                    <CampusFilter options={campusOptions} value={values.campus} onChange={(campus) => updateMany({ campus, class: '' })} />
                    <FormField label={singular} className="sm:w-60">
                        <SelectInput value={values.class} onChange={(event) => update('class', event.target.value)}>
                            <option value="">All {plural.toLowerCase()}</option>
                            {classes.map((classBatch) => (
                                <option key={classBatch.id} value={String(classBatch.id)}>
                                    {classBatch.name}
                                </option>
                            ))}
                        </SelectInput>
                    </FormField>
                </FilterBar>

                {sessions.data.length === 0 ? (
                    <EmptyState
                        icon={CalendarCheck}
                        title={periods.length === 0 ? `No ${plural.toLowerCase()} to keep attendance for` : 'No training sessions'}
                        description={
                            periods.length === 0
                                ? scope === 'all'
                                    ? `Attendance is kept by ${singular.toLowerCase()}. Create a ${singular.toLowerCase()} and assign its candidates first.`
                                    : `Attendance is kept for the ${plural.toLowerCase()} you teach. Ask an administrator to assign you to a ${singular.toLowerCase()}.`
                                : `No sessions are recorded for the selected ${plural.toLowerCase()}. Create a session, then take the roll call.`
                        }
                        action={newSessionAction || undefined}
                    />
                ) : (
                    <Table caption="Training sessions" className="min-w-[60rem]">
                        <TableHead>
                            <Th>Date</Th>
                            <Th>Session</Th>
                            <Th>{singular}</Th>
                            <Th align="right">Recorded</Th>
                            <Th align="right">Present</Th>
                            <Th align="right">Late</Th>
                            <Th align="right">Excused</Th>
                            <Th align="right">Absent</Th>
                            <Th align="right">
                                <span className="sr-only">Actions</span>
                            </Th>
                        </TableHead>
                        <TableBody>
                            {sessions.data.map((session) => {
                                const complete = session.counts.recorded >= session.rosterCount;

                                return (
                                    <Tr key={session.id}>
                                        <Td className="whitespace-nowrap text-ink">{formatCalendarDate(session.heldOn)}</Td>
                                        <Td className="text-ink">
                                            <span className="font-medium">{session.title}</span>
                                            <span className="block text-xs text-ink-muted">{formatHours(session.hours)}</span>
                                        </Td>
                                        <Td className="text-ink">{session.classBatch.name}</Td>
                                        <Td align="right" numeric className={complete ? 'text-ink' : 'font-semibold text-warning-fg'}>
                                            {session.counts.recorded} of {session.rosterCount}
                                            {!complete && <span className="block text-xs font-normal">Not complete</span>}
                                        </Td>
                                        <Td align="right" numeric className="text-ink">
                                            {session.counts.present}
                                        </Td>
                                        <Td align="right" numeric className="text-ink">
                                            {session.counts.late}
                                        </Td>
                                        <Td align="right" numeric className="text-ink">
                                            {session.counts.excused}
                                        </Td>
                                        <Td align="right" numeric className={session.counts.absent > 0 ? 'font-semibold text-danger-fg' : 'text-ink'}>
                                            {session.counts.absent}
                                        </Td>
                                        <Td align="right">
                                            <RowAction href={routes.attendance.sessions.show(session.id)} label={`Open the roll call of ${session.title}, ${session.classBatch.name}`}>
                                                Roll Call
                                            </RowAction>
                                        </Td>
                                    </Tr>
                                );
                            })}
                        </TableBody>
                    </Table>
                )}

                <Pagination page={sessions} noun={{ one: 'session', other: 'sessions' }} />
            </section>
        </>
    );
}
