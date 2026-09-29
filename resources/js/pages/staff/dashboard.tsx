import { Head, usePage } from '@inertiajs/react';
import { BellRing, CalendarClock, ChartColumn, ClipboardList } from 'lucide-react';
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
    /** Institution-wide academic overview, for users who may view all candidates. */
    showAcademicOverview: boolean;
    /** The active period has no passing and warning grades; present only for users who can set them. */
    thresholdSetup: { periodId: number; periodName: string } | null;
    /** Present only for users allowed to view accounts. */
    accountSummary: RoleAccountCount[] | null;
}

export default function Dashboard({ teaching, showAcademicOverview, thresholdSetup, accountSummary }: DashboardProps) {
    const { app, auth } = usePage().props;
    const userName = auth.user?.name ?? '';

    return (
        <>
            <Head title="Dashboard" />

            <PageHeader title="Dashboard" description={`${greeting(app.timezone)}, ${userName}.`} />

            <div className="flex flex-col gap-6">
                {teaching !== null && <TeachingSection teaching={teaching} />}

                {showAcademicOverview ? (
                    <Panel title="Academic Overview">
                        <EmptyState
                            icon={ChartColumn}
                            headingLevel="h3"
                            title="The academic overview is not available yet"
                            description={
                                thresholdSetup === null
                                    ? 'Counts of passing, at-risk, failing, and incomplete candidates will be shown here. A candidate’s current standing is shown on their profile once their academic period has passing and warning grades.'
                                    : `Counts of passing, at-risk, failing, and incomplete candidates will be shown here. Academic standing needs passing and warning grades for ${thresholdSetup.periodName}, which have not been set yet.`
                            }
                            action={thresholdSetup !== null && <ThresholdSetupLink setup={thresholdSetup} />}
                        />
                    </Panel>
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

function TeachingSection({ teaching }: { teaching: TeachingOverview }) {
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
                    <Panel title="Academic Alerts" headingLevel="h3">
                        <EmptyState
                            icon={BellRing}
                            headingLevel="h4"
                            title="Academic alerts are not available yet"
                            description={
                                teaching.period.hasThresholds
                                    ? 'Candidates who are at risk or failing in your subjects will be listed here. Until then, each subject’s gradebook shows the current standing of its candidates.'
                                    : `Candidates who are at risk or failing in your subjects will be listed here. Academic standing is not available yet: passing and warning grades have not been set for ${teaching.period.name}. An academic administrator sets them for each period.`
                            }
                        />
                    </Panel>
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
