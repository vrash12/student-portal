import { Head, usePage } from '@inertiajs/react';
import { ClipboardList, Clock3 } from 'lucide-react';
import { Link } from '@inertiajs/react';
import { EmptyState } from '@/components/ui/empty-state';
import { Panel } from '@/components/ui/panel';

/**
 * Candidate portal home. Listing available examinations arrives with the
 * examination modules (Milestones 8–9).
 */
export default function PortalHome({ examinations = [] }: { examinations?: Array<{ id: number; title: string; subject: string; durationMinutes: number | null; closesAt: string | null }> }) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Examinations" />

            <div className="mb-6">
                <h1 className="text-2xl font-semibold tracking-tight text-ink">Examinations</h1>
                <p className="mt-1 text-base text-ink-muted">Welcome, {auth.user?.name}.</p>
            </div>

            <Panel title="Available Examinations">
                {examinations.length === 0 ? <EmptyState
                    icon={ClipboardList}
                    headingLevel="h3"
                    title="No examinations available"
                    description="Quizzes and examinations appear here when they are published to your class and open for taking."
                /> : <div className="grid gap-4 sm:grid-cols-2">{examinations.map((exam) => <Link key={exam.id} href={`/portal/examinations/${exam.id}`} className="rounded-xl border border-line bg-surface p-5 shadow-sm transition hover:border-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-600"><div className="flex items-start justify-between gap-3"><div><p className="text-lg font-semibold text-ink">{exam.title}</p><p className="mt-1 text-sm text-ink-muted">{exam.subject}</p></div><ClipboardList className="size-6 text-primary-600" aria-hidden="true" /></div><div className="mt-5 flex flex-wrap gap-4 text-sm text-ink-muted"><span className="inline-flex items-center gap-2"><Clock3 className="size-4" aria-hidden="true" />{exam.durationMinutes ?? 'No'} minute limit</span><span>Open now</span></div></Link>)}</div>}
            </Panel>
        </>
    );
}