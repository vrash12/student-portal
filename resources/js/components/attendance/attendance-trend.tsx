import { ChartFigure } from '@/components/charts/chart-figure';
import { LineChart } from '@/components/charts/line-chart';
import { PieChart } from '@/components/charts/pie-chart';
import type { AttendanceCounts, AttendanceTrend } from '@/types/attendance';
import type { ChartTone } from '@/types/charts';

/** Statuses in the order shown, with their chart colors (always listed with the label and count). */
const STATUSES: ReadonlyArray<{ key: keyof AttendanceCounts; label: string; tone: ChartTone }> = [
    { key: 'present', label: 'Present', tone: 'passing' },
    { key: 'late', label: 'Late', tone: 'atRisk' },
    { key: 'excused', label: 'Excused', tone: 'incomplete' },
    { key: 'absent', label: 'Absent', tone: 'failing' },
];

/** The records of the sessions by status, as a ring with the counts and shares beside it. */
export function AttendanceStatusPie({ counts, noun = { one: 'record', other: 'records' } }: { counts: AttendanceCounts; noun?: { one: string; other: string } }) {
    return <PieChart slices={STATUSES.map((status) => ({ label: status.label, value: counts[status.key], tone: status.tone }))} noun={noun} listEmpty />;
}

/** Whether any attendance is recorded in the trend. */
export function hasAttendance(trend: AttendanceTrend): boolean {
    return STATUSES.some((status) => trend.totals[status.key] > 0);
}

/**
 * Attendance over the latest training days (AttendanceLedger::dailyRates):
 * the rate of each day as a line, and every record by status. Days with
 * only excused records have no rate and no point.
 */
export function AttendanceTrendCharts({ trend }: { trend: AttendanceTrend }) {
    const points = trend.days.flatMap((day) =>
        day.rate === null
            ? []
            : [{ date: day.date, value: day.rate, detail: `${day.present + day.late} of ${day.present + day.late + day.absent} attended` }],
    );

    return (
        <div className="grid gap-x-8 gap-y-6 lg:grid-cols-3">
            <ChartFigure
                title="Attendance Rate by Day"
                description={`Present and late records out of those counted on each training day; excused records are left out. The latest ${trend.days.length === 1 ? 'day' : `${trend.days.length} days`} with records.`}
                className="lg:col-span-2"
            >
                <LineChart
                    data={{ xType: 'date', series: [{ label: 'Attendance rate', tone: 'c1', points }] }}
                    format="percent"
                    yMax={100}
                    label="Attendance rate by day"
                    xLabel="Training day"
                    height={220}
                />
            </ChartFigure>
            <ChartFigure title="Records by Status" description="Every attendance record of these sessions.">
                <AttendanceStatusPie counts={trend.totals} />
            </ChartFigure>
        </div>
    );
}
