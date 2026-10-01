import { Head, Link, usePage } from '@inertiajs/react';
import { Award, BellRing, CalendarClock, ChartColumn, ClipboardList } from 'lucide-react';
import { BarList } from '@/components/charts/bar-list';
import { ChartFigure } from '@/components/charts/chart-figure';
import { ColumnChart } from '@/components/charts/column-chart';
import { StandingBreakdown, StandingDistribution } from '@/components/charts/standing-breakdown';
import { StandingBadge } from '@/components/grading/standing';
import { AttentionList } from '@/components/monitoring/attention-list';
import { QualificationDistribution } from '@/components/performance/qualification-distribution';
import { StandingCounts } from '@/components/monitoring/standing-counts';
import { Alert } from '@/components/ui/alert';
import { ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { MetricCard } from '@/components/ui/metric-card';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction } from '@/components/ui/table';
import { formatCalendarDate, formatGrade, useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import type { QualificationOverviewData } from '@/types/candidate-performance';
import type { ChartColumn as DistributionColumn } from '@/types/charts';
import type { GradingThresholds, StatusValue } from '@/types/grading';
import type { MonitoringSummary, SubjectStandingSummary } from '@/types/monitoring';

interface RoleAccountCount {
    code: string;
    name: string;
    activeUsers: number;
}

interface TeachingAssignment {
    id: number;
    /** Identifies the subject's gradebook in this class. */
    classSubjectId: number;
    subject: { code: string; name: string };
    classBatch: { id: number; name: string };
    enrolledCount: number;
}

interface UpcomingAssessment {
    id: number;
    title: string;
    assessedOn: string;
    subject: string;
    classBatch: string;
    status: StatusValue;
}

interface TeachingOverview {
    /** hasThresholds: the period has passing and warning grades, so gradebooks show standing. */
    period: { id: number; name: string; hasThresholds: boolean } | null;
    assignments: TeachingAssignment[];
    totals: { subjects: number; classes: number; enrolledCandidates: number };
    upcomingAssessments: UpcomingAssessment[];
}

interface DashboardProps {
    /** Present only for teaching staff (classes.teach). */
    teaching: TeachingOverview | null;
    /** Institution-wide academic overview: academic monitoring plus "view all candidates". */
    showAcademicOverview: boolean;
    /** Active-period standings of every candidate; null when no period is active. */
    academicOverview: MonitoringSummary | null;
    /** Academic alerts over the subjects the user teaches: academic monitoring plus teaching. */
    showAcademicAlerts: boolean;
    academicAlerts: MonitoringSummary | null;
    /** The active period has no passing and warning grades; present only for users who can set them. */
    thresholdSetup: { periodId: number; periodName: string } | null;
    /** Present only for users allowed to view accounts. */
    accountSummary: RoleAccountCount[] | null;
    administratorOverview: AdministratorOverviewData | null;
    /** Qualification of every candidate: performance.view. */
    showQualification: boolean;
    /** Qualification across the classes of the active period; null when no period is active. */
    qualificationOverview: QualificationOverviewData | null;
    /** The user may configure the performance areas (performance.configure). */
    canConfigurePerformance: boolean;
}

interface AdministratorOverviewData {
    period: { id: number; name: string } | null;
    totalCandidates: number;
    recentExaminations: Array<{ id: number; title: string; subject: string; classBatch: string; status: StatusValue; submittedCount: number }>;
    recentActivity: Array<{ id: number; title: string; subject: string; classBatch: string; finalizedAt: string | null; scoredCount: number }>;
    subjectPerformance: Array<{ id: number; subject: string; average: number | null; gradedCount: number; classId: number; classBatch: string; subjectId: number; provisionalCount: number }>;
    /** Current subject grades by range, lowest first; empty when nothing is graded. */
    gradeDistribution: DistributionColumn[];
    thresholds: GradingThresholds | null;
    instructors: Array<{ id: number; name: string; classes: string[]; subjects: string[] }>;
}

export default function Dashboard({
    teaching,
    showAcademicOverview,
    academicOverview,
    showAcademicAlerts,
    academicAlerts,
    thresholdSetup,
    accountSummary,
    administratorOverview,
    showQualification,
    qualificationOverview,
    canConfigurePerformance,
}: DashboardProps) {
    const { app, auth } = usePage().props;
    const userName = auth.user?.name ?? '';

    return (
        <>
            <Head title="Dashboard" />

            <PageHeader title="Dashboard" description={`${greeting(app.timezone)}, ${userName}.`} />

            <div className="flex flex-col gap-6">
                {teaching !== null && <TeachingSection teaching={teaching} alerts={showAcademicAlerts ? academicAlerts : undefined} />}

                {showAcademicOverview ? (
                    <AcademicOverview summary={academicOverview} thresholdSetup={thresholdSetup} />
                ) : (
                    thresholdSetup !== null && (
                        <Alert title={`Passing and warning grades are not set for ${thresholdSetup.periodName}`}>
                            <p>Academic standing is not shown until they are set.</p>
                            <div className="mt-2">
                                <ThresholdSetupLink setup={thresholdSetup} />
                            </div>
                        </Alert>
                    )
                )}

                {showQualification && <QualificationOverview overview={qualificationOverview} canConfigure={canConfigurePerformance} />}

                {administratorOverview !== null && <AdministratorOverview overview={administratorOverview} />}


                {accountSummary !== null && (
                    <Panel title="Active Accounts" description="Accounts that can currently sign in, by role.">
                        <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            {accountSummary.map((role) => (
                                <MetricCard key={role.code} label={role.name} value={role.activeUsers} />
                            ))}
                        </dl>
                    </Panel>
                )}
            </div>
        </>
    );
}

/**
 * Qualification across every class of the active period (performance.view),
 * from the server's QualificationEngine: candidates per status and the
 * required area failed most often. The class pages list the candidates.
 */
function QualificationOverview({ overview, canConfigure }: { overview: QualificationOverviewData | null; canConfigure: boolean }) {
    const { plural } = terms.classBatch;

    if (overview === null) {
        return (
            <Panel title="Qualification">
                <EmptyState
                    icon={CalendarClock}
                    headingLevel="h3"
                    title="No active academic period"
                    description="Qualification of the active period appears here. Set an academic period as active to see it."
                />
            </Panel>
        );
    }

    if (!overview.configured) {
        return (
            <Panel title="Qualification" description={overview.period.name}>
                <EmptyState
                    icon={Award}
                    headingLevel="h3"
                    title="No performance areas are configured"
                    description="Qualification cannot be decided until the areas, their weights and passing grades are set."
                    action={
                        canConfigure && (
                            <ButtonLink href={routes.performanceAreas.index()} variant="secondary">
                                Configure Performance Areas
                            </ButtonLink>
                        )
                    }
                />
            </Panel>
        );
    }

    const { counts, mostCommonUnmet } = overview;

    return (
        <Panel
            title="Qualification"
            description={`${overview.period.name} · ${overview.classCount} ${(overview.classCount === 1 ? terms.classBatch.singular : plural).toLowerCase()} · candidates who are not withdrawn`}
            actions={
                <ButtonLink href={routes.qualification.index()} variant="secondary">
                    Open Qualification
                </ButtonLink>
            }
        >
            {counts.total === 0 ? (
                <p className="text-sm text-ink">No candidates are assigned to the {plural.toLowerCase()} of {overview.period.name} yet.</p>
            ) : (
                <div className="flex flex-col gap-5">
                    <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <MetricCard label="Candidates" value={counts.total} />
                        <MetricCard label="Qualified" value={counts.qualified} description="Passed every required area." />
                        <MetricCard label="Not Qualified" value={counts.notQualified} description="Failed at least one required area." />
                        <MetricCard label="Pending" value={counts.pending} description="Waiting for results in a required area." />
                    </dl>
                    <ChartFigure title="Qualification Status" description="Share of candidates in each qualification status.">
                        <QualificationDistribution counts={counts} />
                    </ChartFigure>
                    <p className="text-sm text-ink">
                        {mostCommonUnmet === null ? (
                            'No required area is failed by any candidate.'
                        ) : (
                            <>
                                Most common unmet requirement: <span className="font-semibold">{mostCommonUnmet.name}</span>, failed by{' '}
                                <span className="font-semibold tabular-nums">{mostCommonUnmet.count}</span> {mostCommonUnmet.count === 1 ? 'candidate' : 'candidates'}.
                            </>
                        )}
                    </p>
                </div>
            )}
        </Panel>
    );
}

function AdministratorOverview({ overview }: { overview: AdministratorOverviewData }) {
    // finalizedAt is a timestamp: format it in the institution's timezone, not as a calendar date.
    const formatDate = useDateFormatter();
    const { singular, plural } = terms.classBatch;

    if (overview.period === null) {
        return null;
    }

    const period = overview.period;
    const references =
        overview.thresholds === null
            ? []
            : [
                  { label: 'Passing grade', value: overview.thresholds.passingGrade },
                  { label: 'Warning grade', value: overview.thresholds.warningGrade },
              ];

    return (
        <section aria-labelledby="administrator-overview-heading" className="flex flex-col gap-4">
            <div>
                <h2 id="administrator-overview-heading" className="text-lg font-semibold text-ink">
                    Institutional Activity
                </h2>
                <p className="text-sm text-ink-muted">{period.name} · subject performance, current examinations, finalized work, and teaching coverage</p>
            </div>
            <dl>
                <MetricCard label="Candidates in active period" value={overview.totalCandidates} />
            </dl>
            <div className="grid gap-6 lg:grid-cols-2">
                <Panel
                    title="Subject Performance"
                    description={`Mean current weighted grade of each subject per ${singular.toLowerCase()}, from the grade engine; includes provisional grades.`}
                    headingLevel="h3"
                >
                    {overview.subjectPerformance.length === 0 ? (
                        <p className="text-sm text-ink-muted">No finalized scores are available yet.</p>
                    ) : (
                        <ChartFigure title="Mean grade by subject">
                            <BarList
                                bars={overview.subjectPerformance.map((subject) => ({ label: `${subject.subject} · ${subject.classBatch}`, value: subject.average }))}
                                references={references}
                                renderLabel={(bar, index) => {
                                    const subject = overview.subjectPerformance[index];
                                    if (subject === undefined) {
                                        return bar.label;
                                    }

                                    return (
                                        <Link
                                            className="text-primary-700 underline"
                                            href={routes.monitoring.index({ period: String(period.id), class: String(subject.classId), subject: String(subject.subjectId) })}
                                        >
                                            {bar.label}
                                        </Link>
                                    );
                                }}
                            />
                        </ChartFigure>
                    )}
                </Panel>
                <Panel
                    title="Grade Distribution"
                    description="Current subject grades of every candidate by range. Ranges are descriptive; standing follows the passing and warning grades."
                    headingLevel="h3"
                >
                    {overview.gradeDistribution.length === 0 ? (
                        <p className="text-sm text-ink-muted">No subject grades are recorded for this period yet.</p>
                    ) : (
                        <ChartFigure title="Candidate-subject grades by range">
                            <ColumnChart columns={overview.gradeDistribution} noun={{ one: 'grade', other: 'grades' }} />
                        </ChartFigure>
                    )}
                </Panel>
                <Panel title="Recent Examinations" headingLevel="h3" bodyClassName={overview.recentExaminations.length === 0 ? undefined : 'p-0'}>
                    {overview.recentExaminations.length === 0 ? (
                        <p className="text-sm text-ink-muted">No examinations have been created for this period.</p>
                    ) : (
                        <ul className="divide-y divide-line">
                            {overview.recentExaminations.map((exam) => (
                                <li key={exam.id} className="flex items-start justify-between gap-3 px-5 py-3">
                                    <div>
                                        <p className="font-medium">{exam.title}</p>
                                        <p className="text-sm text-ink-muted">
                                            {exam.subject} · {exam.classBatch}
                                        </p>
                                        <p className="mt-1 text-sm">{exam.submittedCount} submitted</p>
                                    </div>
                                    <StatusBadge tone={exam.status.tone}>{exam.status.label}</StatusBadge>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
                <Panel title="Recent Academic Activity" headingLevel="h3" bodyClassName={overview.recentActivity.length === 0 ? undefined : 'p-0'}>
                    {overview.recentActivity.length === 0 ? (
                        <p className="text-sm text-ink-muted">No finalized assessments have been recorded for this period.</p>
                    ) : (
                        <ul className="divide-y divide-line">
                            {overview.recentActivity.map((activity) => (
                                <li key={activity.id} className="px-5 py-3">
                                    <p className="font-medium">{activity.title}</p>
                                    <p className="text-sm text-ink-muted">
                                        {activity.subject} · {activity.classBatch}
                                    </p>
                                    <p className="mt-1 text-sm">
                                        {activity.scoredCount} scores recorded{activity.finalizedAt ? ` · finalized ${formatDate.date(activity.finalizedAt)}` : ''}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
                <Panel
                    title="Instructor Coverage"
                    description="Assignments in the active period."
                    headingLevel="h3"
                    bodyClassName={overview.instructors.length === 0 ? undefined : 'p-0'}
                >
                    {overview.instructors.length === 0 ? (
                        <p className="text-sm text-ink-muted">No instructor assignments are recorded for this period.</p>
                    ) : (
                        <ul className="divide-y divide-line">
                            {overview.instructors.map((instructor) => (
                                <li key={instructor.id} className="px-5 py-3">
                                    <p className="font-medium">{instructor.name}</p>
                                    <p className="text-sm text-ink-muted">
                                        {instructor.subjects.length} {instructor.subjects.length === 1 ? 'subject' : 'subjects'} · {instructor.classes.length}{' '}
                                        {(instructor.classes.length === 1 ? singular : plural).toLowerCase()}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </section>
    );
}

/**
 * Institution-wide standings of the active period (UI_UX_DESIGN.md §18):
 * counts first, then the candidates requiring attention.
 */
function AcademicOverview({
    summary,
    thresholdSetup,
}: {
    summary: MonitoringSummary | null;
    thresholdSetup: { periodId: number; periodName: string } | null;
}) {
    if (summary === null) {
        return (
            <Panel title="Academic Overview">
                <EmptyState
                    icon={CalendarClock}
                    headingLevel="h3"
                    title="No active academic period"
                    description="Standings of the active period appear here. Set an academic period as active to see them."
                />
            </Panel>
        );
    }

    if (!summary.hasThresholds) {
        return (
            <Panel title="Academic Overview" description={summary.period.name}>
                <EmptyState
                    icon={ChartColumn}
                    headingLevel="h3"
                    title="Academic standing is not available yet"
                    description={`Passing and warning grades have not been set for ${summary.period.name}. Standings, and the candidates requiring attention, appear here once they are set.`}
                    action={thresholdSetup !== null && <ThresholdSetupLink setup={thresholdSetup} />}
                />
            </Panel>
        );
    }

    const { counts } = summary;

    return (
        <Panel
            title="Academic Overview"
            description={`${summary.period.name} · each candidate’s most serious subject standing`}
            actions={
                <ButtonLink href={routes.monitoring.index()} variant="secondary">
                    Open Academic Monitoring
                </ButtonLink>
            }
        >
            {counts.monitored === 0 || counts.noStanding === counts.monitored ? (
                <p className="text-sm text-ink">
                    {counts.monitored === 0
                        ? `No candidates are assigned to the classes of ${summary.period.name} yet.`
                        : `No standings yet for the ${counts.monitored} monitored ${counts.monitored === 1 ? 'candidate' : 'candidates'}: standings appear once assessments are finalized.`}
                </p>
            ) : (
                <div className="flex flex-col gap-5">
                    <StandingCounts
                        counts={counts}
                        totalLabel="Monitored Candidates"
                        standings={['failing', 'at_risk', 'incomplete', 'passing']}
                        hrefFor={(standing) => routes.monitoring.index({ standing })}
                    />
                    <ChartFigure title="Standing Distribution" description="Share of monitored candidates in each overall standing.">
                        <StandingDistribution
                            counts={{
                                passing: counts.passing,
                                atRisk: counts.atRisk,
                                failing: counts.failing,
                                incomplete: counts.incomplete,
                                noStanding: counts.noStanding,
                            }}
                        />
                    </ChartFigure>
                    <section aria-labelledby="attention-heading" className="-mx-5 -mb-5 border-t border-line">
                        <h3 id="attention-heading" className="px-5 pt-4 text-sm font-semibold text-ink">
                            Candidates Requiring Attention
                        </h3>
                        {summary.requiringAttention.length === 0 ? (
                            <p className="px-5 pb-4 pt-1 text-sm text-ink-muted">No candidates are failing or at risk in {summary.period.name}.</p>
                        ) : (
                            <AttentionList candidates={summary.requiringAttention} />
                        )}
                    </section>
                </div>
            )}
        </Panel>
    );
}

/**
 * The instructor's own alerts: distinct candidates failing or at risk in
 * the subjects they teach in the active period (UI_UX_DESIGN.md §19).
 */
function AcademicAlerts({ summary, period }: { summary: MonitoringSummary | null; period: { name: string } }) {
    const ready = summary !== null && summary.hasThresholds;

    return (
        <Panel
            title="Academic Alerts"
            headingLevel="h3"
            bodyClassName="p-0"
        >
            {!ready ? (
                <EmptyState
                    icon={BellRing}
                    headingLevel="h4"
                    title="Academic standing is not available yet"
                    description={`Passing and warning grades have not been set for ${period.name}. An academic administrator sets them for each period.`}
                />
            ) : (
                <AlertsBody summary={summary} />
            )}
        </Panel>
    );
}

/** How many candidates the instructor dashboard lists by name; the rest are one click away. */
const ALERT_PREVIEW = 3;

function AlertsBody({ summary }: { summary: MonitoringSummary }) {
    const { counts } = summary;

    if (counts.monitored === 0 || counts.noStanding === counts.monitored) {
        return (
            <p className="px-5 py-4 text-sm text-ink">
                {counts.monitored === 0
                    ? 'No candidates are assigned to the classes you teach yet.'
                    : 'No standings yet in your subjects: standings appear once assessments are finalized.'}
            </p>
        );
    }

    const preview = summary.requiringAttention.slice(0, ALERT_PREVIEW);
    const concerned = counts.failing + counts.atRisk;

    return (
        <div className="space-y-3 p-4">
            <div className="grid grid-cols-2 gap-2">
                <AlertCount href={routes.monitoring.index({ standing: 'failing' })} label="Failing" count={counts.failing} tone="danger" />
                <AlertCount href={routes.monitoring.index({ standing: 'at_risk' })} label="At Risk" count={counts.atRisk} tone="warning" />
            </div>
            <p className="text-xs text-ink-muted">
                <span className="tabular-nums">{concerned}</span> of <span className="tabular-nums">{counts.monitored}</span> monitored candidates in your subjects.
            </p>

            {preview.length > 0 && (
                <ul className="divide-y divide-line rounded-lg border border-line">
                    {preview.map((entry) => (
                        <li key={entry.candidate.id}>
                            <Link
                                href={routes.candidates.show(entry.candidate.id)}
                                className="flex min-h-11 items-center gap-2 px-3 py-2 text-sm hover:bg-surface-muted"
                                aria-label={`${entry.candidate.name}${entry.mostSerious ? `, ${entry.mostSerious.subject}` : ''}`}
                            >
                                <span className="min-w-0 flex-1 truncate font-medium text-ink">{entry.candidate.name}</span>
                                <StandingBadge standing={entry.standing} />
                                {entry.mostSerious?.grade != null && <span className="w-12 text-right tabular-nums text-ink-muted">{formatGrade(entry.mostSerious.grade)}</span>}
                            </Link>
                        </li>
                    ))}
                </ul>
            )}

            {concerned > preview.length && (
                <Link href={routes.monitoring.index()} className="inline-flex min-h-11 items-center text-sm font-medium text-primary-700 hover:underline">
                    View all {concerned} candidates
                </Link>
            )}
        </div>
    );
}

function AlertCount({ href, label, count, tone }: { href: string; label: string; count: number; tone: 'danger' | 'warning' }) {
    return (
        <Link
            href={href}
            className={
                'flex items-center justify-between rounded-lg border px-3 py-2 hover:shadow-sm ' +
                (tone === 'danger' ? 'border-danger-border bg-danger-bg text-danger-fg' : 'border-warning-border bg-warning-bg text-warning-fg')
            }
        >
            <span className="text-sm font-medium">{label}</span>
            <span className="text-xl font-bold tabular-nums">{count}</span>
        </Link>
    );
}

function TeachingSection({ teaching, alerts }: { teaching: TeachingOverview; alerts?: MonitoringSummary | null }) {
    const { singular, plural } = terms.classBatch;

    if (teaching.period === null) {
        return (
            <Panel title="My Teaching">
                <EmptyState
                    icon={CalendarClock}
                    headingLevel="h3"
                    title="No active academic period"
                    description="Your subjects and classes appear here once an academic period is active."
                />
            </Panel>
        );
    }

    return (
        <section aria-labelledby="my-teaching-heading" className="flex flex-col gap-4">
            <div>
                <h2 id="my-teaching-heading" className="text-lg font-semibold text-ink">
                    My Teaching
                </h2>
                <p className="text-sm text-ink-muted">{teaching.period.name}</p>
            </div>

            <dl className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <MetricCard label="Subjects Assigned" value={teaching.totals.subjects} />
                <MetricCard label={plural} value={teaching.totals.classes} />
                <MetricCard label="Enrolled Candidates" value={teaching.totals.enrolledCandidates} />
            </dl>

            <div className="grid gap-6 lg:grid-cols-3">
                <Panel
                    title="My Subjects"
                    description={`Subjects you teach this period, by ${singular.toLowerCase()}.`}
                    className="lg:col-span-2 lg:self-start"
                    bodyClassName="p-0"
                    headingLevel="h3"
                >
                    {teaching.assignments.length === 0 ? (
                        <EmptyState
                            icon={ClipboardList}
                            headingLevel="h4"
                            title="No teaching assignments"
                            description={`You are not assigned to teach any subjects in ${teaching.period.name}. Contact an academic administrator if this is unexpected.`}
                        />
                    ) : (
                        <ul className="divide-y divide-line">
                            {teaching.assignments.map((assignment) => (
                                <li key={assignment.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                                    <div className="min-w-0">
                                        <p className="font-medium text-ink">
                                            {assignment.subject.name}{' '}
                                            <span className="font-normal text-ink-muted">({assignment.subject.code})</span>
                                        </p>
                                        <p className="text-sm text-ink-muted">
                                            {assignment.classBatch.name} ·{' '}
                                            <span className="tabular-nums">{assignment.enrolledCount}</span> enrolled
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-1">
                                        <RowAction
                                            href={routes.teaching.gradebook(assignment.classBatch.id, assignment.classSubjectId)}
                                            label={`Gradebook for ${assignment.subject.name}, ${assignment.classBatch.name}`}
                                        >
                                            Gradebook
                                        </RowAction>
                                        <RowAction
                                            href={routes.teaching.classes.show(assignment.classBatch.id)}
                                            label={`View ${singular} ${assignment.classBatch.name}`}
                                        >
                                            View {singular}
                                        </RowAction>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <div className="flex flex-col gap-6">
                    {alerts !== undefined && <AcademicAlerts summary={alerts} period={teaching.period} />}
                    <UpcomingAssessments assessments={teaching.upcomingAssessments} />
                </div>
            </div>

            {alerts?.hasThresholds && alerts.subjects !== undefined && alerts.subjects.length > 0 && <SubjectStandings subjects={alerts.subjects} />}
        </section>
    );
}

/** Each class subject the instructor teaches, split by standing, so the subject needing help stands out. */
function SubjectStandings({ subjects }: { subjects: SubjectStandingSummary[] }) {
    return (
        <Panel
            title="Standing by Subject"
            description="Candidates of each subject you teach by their standing in that subject, with the mean current grade. Subjects with the most failing candidates come first."
            headingLevel="h3"
        >
            <StandingBreakdown
                groups={subjects.map((subject) => ({ label: `${subject.subject} · ${subject.classBatch}`, counts: subject.counts, average: subject.average }))}
                noun={{ one: 'candidate', other: 'candidates' }}
                renderLabel={(group, index) => {
                    const subject = subjects[index];

                    return subject === undefined ? (
                        group.label
                    ) : (
                        <Link href={routes.teaching.gradebook(subject.classId, subject.classSubjectId)} className="text-primary-700 underline">
                            {group.label}
                        </Link>
                    );
                }}
            />
        </Panel>
    );
}

function UpcomingAssessments({ assessments }: { assessments: UpcomingAssessment[] }) {
    return (
        <Panel
            title="Upcoming Assessments"
            description="Dated assessments of your subjects, from today."
            headingLevel="h3"
            bodyClassName={assessments.length === 0 ? undefined : 'p-0'}
        >
            {assessments.length === 0 ? (
                <EmptyState
                    icon={CalendarClock}
                    headingLevel="h4"
                    title="No upcoming assessments"
                    description="Assessments with a date from today onward appear here. Set a date when you create an assessment."
                />
            ) : (
                <ul className="divide-y divide-line">
                    {assessments.map((assessment) => (
                        <li key={assessment.id} className="flex items-start justify-between gap-3 px-5 py-3">
                            <div className="min-w-0">
                                <p className="font-medium text-ink">{assessment.title}</p>
                                <p className="text-sm text-ink-muted">
                                    {assessment.subject} · {assessment.classBatch}
                                </p>
                                <p className="mt-1 flex flex-wrap items-center gap-2 text-sm text-ink">
                                    {formatCalendarDate(assessment.assessedOn)}
                                    <StatusBadge tone={assessment.status.tone}>{assessment.status.label}</StatusBadge>
                                </p>
                            </div>
                            <RowAction href={routes.assessments.show(assessment.id)} label={`Open ${assessment.title}, ${assessment.subject}`}>
                                Open
                            </RowAction>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

function ThresholdSetupLink({ setup }: { setup: { periodId: number; periodName: string } }) {
    return (
        <ButtonLink href={routes.academicPeriods.thresholds(setup.periodId)} variant="secondary">
            Set Passing and Warning Grades
        </ButtonLink>
    );
}

function greeting(timeZone: string): string {
    const hour = Number(
        new Intl.DateTimeFormat('en-US', { hour: 'numeric', hourCycle: 'h23', timeZone }).format(new Date()),
    );

    if (hour < 12) {
        return 'Good morning';
    }

    return hour < 18 ? 'Good afternoon' : 'Good evening';
}
