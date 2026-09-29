import { Head, usePage } from '@inertiajs/react';
import { BellRing, CalendarClock, ChartColumn, ClipboardList } from 'lucide-react';
import { EmptyState } from '@/components/ui/empty-state';
import { MetricCard } from '@/components/ui/metric-card';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { RowAction } from '@/components/ui/table';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';

interface RoleAccountCount {
    code: string;
    name: string;
    activeUsers: number;
}

interface TeachingAssignment {
    id: number;
    subject: { code: string; name: string };
    classBatch: { id: number; name: string };
    enrolledCount: number;
}

interface TeachingOverview {
    period: { id: number; name: string } | null;
    assignments: TeachingAssignment[];
    totals: { subjects: number; classes: number; enrolledCandidates: number };
}

interface DashboardProps {
    /** Present only for teaching staff (classes.teach). */
    teaching: TeachingOverview | null;
    /** Institution-wide academic overview, for users who may view all candidates. */
    showAcademicOverview: boolean;
    /** Present only for users allowed to view accounts. */
    accountSummary: RoleAccountCount[] | null;
}

export default function Dashboard({ teaching, showAcademicOverview, accountSummary }: DashboardProps) {
    const { app, auth } = usePage().props;
    const userName = auth.user?.name ?? '';

    return (
        <>
            <Head title="Dashboard" />

            <PageHeader title="Dashboard" description={`${greeting(app.timezone)}, ${userName}.`} />

            <div className="flex flex-col gap-6">
                {teaching !== null && <TeachingSection teaching={teaching} />}

                {showAcademicOverview && (
                    <Panel title="Academic Overview">
                        <EmptyState
                            icon={ChartColumn}
                            headingLevel="h3"
                            title="Academic monitoring is not set up yet"
                            description="Candidate standings, at-risk alerts, and upcoming assessments will appear here once grades are recorded in the system."
                        />
                    </Panel>
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
                                    <RowAction
                                        href={routes.teaching.classes.show(assignment.classBatch.id)}
                                        label={`View ${singular} ${assignment.classBatch.name}`}
                                    >
                                        View {singular}
                                    </RowAction>
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
                            description="Candidates who are at risk or failing in your subjects will be listed here once grades are recorded."
                        />
                    </Panel>
                    <Panel title="Upcoming Assessments" headingLevel="h3">
                        <EmptyState
                            icon={CalendarClock}
                            headingLevel="h4"
                            title="Upcoming assessments are not available yet"
                            description="Upcoming quizzes and examinations for your subjects will be listed here once assessments are set up."
                        />
                    </Panel>
                </div>
            </div>
        </section>
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
