import { Head, Link, usePage } from '@inertiajs/react';
import { BellRing, CalendarClock, ChartColumn, ClipboardList } from 'lucide-react';
import { AttentionList } from '@/components/monitoring/attention-list';
import { StandingCounts } from '@/components/monitoring/standing-counts';
import { Alert } from '@/components/ui/alert';
import { ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { MetricCard } from '@/components/ui/metric-card';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction } from '@/components/ui/table';
import { formatCalendarDate } from '@/lib/format';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import type { StatusValue } from '@/types/grading';
import type { MonitoringSummary } from '@/types/monitoring';

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
}

export default function Dashboard({
    teaching,
    showAcademicOverview,
    academicOverview,
    showAcademicAlerts,
    academicAlerts,
    thresholdSetup,
    accountSummary,
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
            actions={
                ready && (
                    <RowAction href={routes.monitoring.index()} label="View all in Academic Monitoring">
                        View all
                    </RowAction>
                )
            }
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

    const concerned = counts.failing + counts.atRisk;

    return (
        <>
            <p className="px-5 py-4 text-sm text-ink">
                <span className="font-semibold tabular-nums">{concerned}</span> of <span className="tabular-nums">{counts.monitored}</span> monitored{' '}
                {counts.monitored === 1 ? 'candidate is' : 'candidates are'} failing or at risk in your subjects:{' '}
                <Link href={routes.monitoring.index({ standing: 'failing' })} className="font-medium text-primary-700 underline">
                    <span className="tabular-nums">{counts.failing}</span> failing
                </Link>
                ,{' '}
                <Link href={routes.monitoring.index({ standing: 'at_risk' })} className="font-medium text-primary-700 underline">
                    <span className="tabular-nums">{counts.atRisk}</span> at risk
                </Link>
                .
            </p>
            {summary.requiringAttention.length > 0 && (
                <div className="border-t border-line">
                    <AttentionList candidates={summary.requiringAttention} showGradebook />
                </div>
            )}
        </>
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
                    className="lg:col-span-2"
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
        </section>
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
