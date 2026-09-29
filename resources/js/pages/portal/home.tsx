import { Head, usePage } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';
import { EmptyState } from '@/components/ui/empty-state';
import { Panel } from '@/components/ui/panel';

/**
 * Candidate portal home. Listing available examinations arrives with the
 * examination modules (Milestones 8–9).
 */
export default function PortalHome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Examinations" />

            <div className="mb-6">
                <h1 className="text-2xl font-semibold tracking-tight text-ink">Examinations</h1>
                <p className="mt-1 text-base text-ink-muted">Welcome, {auth.user?.name}.</p>
            </div>

            <Panel title="Available Examinations">
                <EmptyState
                    icon={ClipboardList}
                    headingLevel="h3"
                    title="No examinations available"
                    description="Quizzes and examinations appear here when they are published to your class and open for taking."
                />
            </Panel>
        </>
    );
}
