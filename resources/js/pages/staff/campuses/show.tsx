import { Head, Link } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { BarList } from '@/components/charts/bar-list';
import { ChartFigure } from '@/components/charts/chart-figure';
import { ColumnChart } from '@/components/charts/column-chart';
import { PieChart } from '@/components/charts/pie-chart';
import { StandingBreakdown, StandingDistribution } from '@/components/charts/standing-breakdown';
import type { CampusRow } from '@/components/academic/campus-form';
import { ButtonLink } from '@/components/ui/button';
import { MetricCard } from '@/components/ui/metric-card';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatGrade, formatPercent } from '@/lib/format';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import type { CampusDetails } from '@/types/campus-analytics';

interface CampusAnalyticsProps {
    campus: CampusRow;
    period: { id: number; name: string } | null;
    analytics: CampusDetails;
}

/** One campus's analytics in the active academic year; every figure comes from the server (CampusAnalytics). */
export default function CampusAnalytics({ campus, period, analytics }: CampusAnalyticsProps) {
    const { singular, plural } = terms.classBatch;
    const candidates = { one: terms.candidate.singular.toLowerCase(), other: terms.candidate.plural.toLowerCase() };
    const qualification = analytics.qualification;
    const references =
        analytics.thresholds === null
            ? []
            : [
                  { label: 'Passing grade', value: analytics.thresholds.passingGrade },
                  { label: 'Warning grade', value: analytics.thresholds.warningGrade },
              ];

    return (
        <>
            <Head title={`${campus.name} Analytics`} />

            <PageHeader
                title={`${campus.name} Analytics`}
                description={
                    <>
                        {period === null ? 'No active academic year' : period.name}
                        {campus.address && ` · ${campus.address}`}{' '}
                        {campus.isActive ? <StatusBadge tone="success">Active</StatusBadge> : <StatusBadge tone="neutral">Inactive</StatusBadge>}
                    </>
                }
                breadcrumbs={[{ label: 'Campuses', href: routes.campuses.index() }, { label: campus.name }]}
                actions={
                    <ButtonLink href={routes.campuses.edit(campus.id)} icon={<Pencil className="size-4" aria-hidden="true" />}>
                        Edit Campus
                    </ButtonLink>
                }
            />

            <div className="flex flex-col gap-6">
                <dl className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <MetricCard label={plural} value={analytics.classCount} description="In the active academic year" />
                    <MetricCard label={terms.candidate.plural} value={analytics.candidateCount} description="Not withdrawn" />
                    <MetricCard label="Instructors" value={analytics.instructorCount} description={`${analytics.staffCount} active staff in all`} />
                    <MetricCard
                        label="Attendance rate"
                        value={formatPercent(analytics.attendanceRate)}
                        description={analytics.attendanceRecords === 0 ? 'No attendance recorded yet' : `${analytics.attendanceRecords} records`}
                    />
                </dl>

                {period === null ? (
                    <Panel title="Academic Year">
                        <p className="text-sm text-ink-muted">There is no active academic year yet. Analytics appear once a year is active and has classes at this campus.</p>
                    </Panel>
                ) : (
                    <>
                        <div className="grid gap-6 lg:grid-cols-2">
                            <Panel title="Academic Standing" description="Overall standing of the campus's candidates, from their current subject grades." headingLevel="h2">
                                <ChartFigure title="Candidates by standing">
                                    <StandingDistribution counts={analytics.standing} noun={candidates} />
                                </ChartFigure>
                            </Panel>

                            <Panel title="Qualification" description="Candidates qualified for graduation, from the performance areas." headingLevel="h2">
                                {qualification === null || !qualification.configured ? (
                                    <p className="text-sm text-ink-muted">Performance areas are not set up yet, so nobody is evaluated.</p>
                                ) : (
                                    <div className="flex flex-col gap-4">
                                        <PieChart
                                            slices={[
                                                { label: 'Qualified', value: qualification.counts.qualified, tone: 'passing' },
                                                { label: 'Pending', value: qualification.counts.pending, tone: 'incomplete' },
                                                { label: 'Not qualified', value: qualification.counts.notQualified, tone: 'failing' },
                                            ]}
                                            noun={candidates}
                                            listEmpty
                                        />
                                        {qualification.mostCommonUnmet && (
                                            <p className="text-sm text-ink-muted">
                                                Most common unmet requirement: <span className="font-medium text-ink">{qualification.mostCommonUnmet.name}</span> (
                                                <span className="tabular-nums">{qualification.mostCommonUnmet.count}</span> {candidates.other})
                                            </p>
                                        )}
                                    </div>
                                )}
                            </Panel>
                        </div>

                        <Panel title={`Standing by ${singular}`} description={`Each ${singular.toLowerCase()} of the campus, with its attendance rate.`} headingLevel="h2">
                            {analytics.classes.length === 0 ? (
                                <p className="text-sm text-ink-muted">This campus has no {plural.toLowerCase()} in the active academic year.</p>
                            ) : (
                                <StandingBreakdown
                                    groups={analytics.classes.map((classBatch) => ({ label: classBatch.name, counts: classBatch.standing }))}
                                    renderLabel={(group, index) => {
                                        const classBatch = analytics.classes[index];

                                        return classBatch === undefined ? (
                                            group.label
                                        ) : (
                                            <span>
                                                <Link href={routes.classes.show(classBatch.id)} className="text-primary-700 underline">
                                                    {group.label}
                                                </Link>
                                                <span className="block text-xs font-normal text-ink-muted">
                                                    {classBatch.candidateCount} {classBatch.candidateCount === 1 ? candidates.one : candidates.other} · attendance{' '}
                                                    {formatPercent(classBatch.attendanceRate)}
                                                </span>
                                            </span>
                                        );
                                    }}
                                    noun={candidates}
                                />
                            )}
                        </Panel>

                        <div className="grid gap-6 lg:grid-cols-2">
                            <Panel title="Subject Performance" description={`Mean current grade per subject and ${singular.toLowerCase()}.`} headingLevel="h2">
                                {analytics.subjectPerformance.length === 0 ? (
                                    <p className="text-sm text-ink-muted">No finalized scores yet.</p>
                                ) : (
                                    <ChartFigure title="Mean grade by subject">
                                        <BarList
                                            bars={analytics.subjectPerformance.map((subject) => ({ label: `${subject.subject} · ${subject.classBatch}`, value: subject.average }))}
                                            references={references}
                                        />
                                    </ChartFigure>
                                )}
                            </Panel>
                            <Panel title="Grade Distribution" description="Current subject grades by range." headingLevel="h2">
                                {analytics.gradeDistribution.length === 0 ? (
                                    <p className="text-sm text-ink-muted">No subject grades yet.</p>
                                ) : (
                                    <ChartFigure title="Subject grades by range">
                                        <ColumnChart columns={analytics.gradeDistribution} noun={{ one: 'grade', other: 'grades' }} />
                                    </ChartFigure>
                                )}
                            </Panel>
                            <Panel title="Attendance" description="Every attendance record of the campus's sessions by status." headingLevel="h2">
                                {analytics.attendanceRecords === 0 ? (
                                    <p className="text-sm text-ink-muted">No attendance recorded yet.</p>
                                ) : (
                                    <PieChart
                                        slices={[
                                            { label: 'Present', value: analytics.attendanceTotals.present, tone: 'passing' },
                                            { label: 'Late', value: analytics.attendanceTotals.late, tone: 'atRisk' },
                                            { label: 'Excused', value: analytics.attendanceTotals.excused, tone: 'incomplete' },
                                            { label: 'Absent', value: analytics.attendanceTotals.absent, tone: 'failing' },
                                        ]}
                                        noun={{ one: 'record', other: 'records' }}
                                        listEmpty
                                    />
                                )}
                            </Panel>
                            <Panel title="Military Fitness" description="Results across the campus's fitness tests this year." headingLevel="h2">
                                {analytics.fitness.recorded === 0 ? (
                                    <p className="text-sm text-ink-muted">No fitness results recorded yet.</p>
                                ) : (
                                    <div className="flex flex-col gap-4">
                                        <PieChart
                                            slices={[
                                                { label: 'Passed', value: analytics.fitness.passed, tone: 'passing' },
                                                { label: 'Incomplete', value: analytics.fitness.incomplete, tone: 'incomplete' },
                                                { label: 'Failed', value: analytics.fitness.failed, tone: 'failing' },
                                            ]}
                                            noun={{ one: 'result', other: 'results' }}
                                            listEmpty
                                        />
                                        <p className="text-sm text-ink-muted">
                                            <span className="tabular-nums">{analytics.fitness.tests}</span> {analytics.fitness.tests === 1 ? 'test' : 'tests'} · pass rate{' '}
                                            {formatPercent(analytics.fitness.passRate)} of decided results
                                        </p>
                                    </div>
                                )}
                            </Panel>
                        </div>

                        <div className="grid gap-6 lg:grid-cols-2">
                            <Panel title="Merits & Demerits" description="Points that count (voided entries left out)." headingLevel="h2">
                                <dl className="grid grid-cols-3 gap-4">
                                    <MetricCard label="Merits" value={analytics.conduct.merits} />
                                    <MetricCard label="Demerits" value={analytics.conduct.demerits} />
                                    <MetricCard label="Net" value={analytics.conduct.net > 0 ? `+${analytics.conduct.net}` : analytics.conduct.net} />
                                </dl>
                            </Panel>
                            <Panel title="Quizzes & Examinations" description="Published quizzes and examinations of the campus's subjects." headingLevel="h2">
                                <dl className="grid grid-cols-3 gap-4">
                                    <MetricCard label="Published" value={analytics.examinations.published} />
                                    <MetricCard label="Submitted attempts" value={analytics.examinations.submitted} />
                                    <MetricCard
                                        label="Mean score"
                                        value={analytics.examinations.meanScore === null ? '—' : formatGrade(analytics.examinations.meanScore)}
                                    />
                                </dl>
                            </Panel>
                        </div>

                        <Panel title="Instructors" description="Teaching assignments in the active academic year." headingLevel="h2" bodyClassName={analytics.instructors.length === 0 ? undefined : 'p-0'}>
                            {analytics.instructors.length === 0 ? (
                                <p className="text-sm text-ink-muted">No instructor assignments at this campus yet.</p>
                            ) : (
                                <ul className="divide-y divide-line">
                                    {analytics.instructors.map((instructor) => (
                                        <li key={instructor.id} className="flex flex-wrap items-baseline justify-between gap-2 px-5 py-3">
                                            <p className="font-medium text-ink">{instructor.name}</p>
                                            <p className="text-sm text-ink-muted">
                                                {instructor.subjects.length} {instructor.subjects.length === 1 ? 'subject' : 'subjects'} · {instructor.classes.length}{' '}
                                                {(instructor.classes.length === 1 ? singular : plural).toLowerCase()}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Panel>
                    </>
                )}
            </div>
        </>
    );
}
