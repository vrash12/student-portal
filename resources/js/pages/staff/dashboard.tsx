import { Head, usePage } from '@inertiajs/react';
import { ChartColumn } from 'lucide-react';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';

interface RoleAccountCount {
    code: string;
    name: string;
    activeUsers: number;
}

interface DashboardProps {
    /** Present only for users allowed to view accounts. */
    accountSummary: RoleAccountCount[] | null;
}

export default function Dashboard({ accountSummary }: DashboardProps) {
    const { app, auth } = usePage().props;
    const userName = auth.user?.name ?? '';

    return (
        <>
            <Head title="Dashboard" />

            <PageHeader title="Dashboard" description={`${greeting(app.timezone)}, ${userName}.`} />

            <div className="flex flex-col gap-6">
                <Panel title="Academic Overview">
                    <EmptyState
                        icon={ChartColumn}
                        headingLevel="h3"
                        title="Academic monitoring is not set up yet"
                        description="Candidate standings, at-risk alerts, and upcoming assessments will appear here once candidates, subjects, and grades are recorded in the system."
                    />
                </Panel>

                {accountSummary !== null && (
                    <Panel title="Active Accounts" description="Accounts that can currently sign in, by role.">
                        <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            {accountSummary.map((role) => (
                                <div key={role.code} className="rounded-lg border border-line px-4 py-4">
                                    <dt className="text-sm font-medium text-ink-muted">{role.name}</dt>
                                    <dd className="mt-2 text-3xl font-semibold text-ink tabular-nums">{role.activeUsers}</dd>
                                </div>
                            ))}
                        </dl>
                    </Panel>
                )}
            </div>
        </>
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
