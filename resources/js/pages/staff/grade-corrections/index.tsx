import { Head, Link } from '@inertiajs/react';
import { FilePenLine } from 'lucide-react';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { cn } from '@/lib/cn';
import { useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { Paginated } from '@/types';
import type { GradeCorrectionStatusValue, GradeCorrectionSummary } from '@/types/grade-corrections';

type StatusFilter = GradeCorrectionStatusValue | 'all';

interface GradeCorrectionsIndexProps {
    requests: Paginated<GradeCorrectionSummary>;
    status: StatusFilter;
    /** Requests per status (and in total) that the user can see. */
    counts: Record<StatusFilter, number>;
    statuses: Array<{ value: GradeCorrectionStatusValue; label: string }>;
    /** "all": approvers see every request; "own": the user's and their subjects' requests. */
    scope: 'all' | 'own';
}

/**
 * Grade correction requests (owner request, 2026-10-02). Administrators
 * review the incident reports and approve or reject them; instructors follow
 * their own requests.
 */
export default function GradeCorrectionsIndex({ requests, status, counts, statuses, scope }: GradeCorrectionsIndexProps) {
    const formatDate = useDateFormatter();
    const tabs: Array<{ value: StatusFilter; label: string }> = [...statuses, { value: 'all', label: 'All' }];

    return (
        <>
            <Head title="Grade Corrections" />

            <PageHeader
                title="Grade Corrections"
                description={
                    scope === 'all'
                        ? 'Requests to change finalized scores. Read each incident report, then approve or reject it. A score changes only when approved.'
                        : 'Your requests to change finalized scores. A score changes only after an administrator approves the request. To file one, open a finalized assessment and choose Request Correction.'
                }
            />

            <section className="rounded-lg border border-line bg-surface" aria-label="Grade correction requests">
                <nav aria-label="Filter by status" className="flex flex-wrap gap-2 border-b border-line px-4 py-3">
                    {tabs.map((tab) => {
                        const active = tab.value === status;

                        return (
                            <Link
                                key={tab.value}
                                href={routes.gradeCorrections.index({ status: tab.value })}
                                preserveScroll
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    'inline-flex min-h-11 items-center gap-2 rounded-lg border px-3 text-sm font-medium',
                                    active ? 'border-primary-700 bg-primary-700 text-white' : 'border-line text-ink hover:bg-surface-muted',
                                )}
                            >
                                {tab.label}
                                <span className={cn('rounded-full px-2 text-xs tabular-nums', active ? 'bg-white/20' : 'bg-surface-muted text-ink-muted')}>{counts[tab.value]}</span>
                            </Link>
                        );
                    })}
                </nav>

                {requests.data.length === 0 ? (
                    <EmptyState
                        icon={FilePenLine}
                        title={status === 'pending' ? 'No requests waiting for approval' : 'No correction requests'}
                        description={
                            scope === 'all'
                                ? 'Instructors request corrections from finalized assessments. New requests appear here.'
                                : 'Open a finalized assessment in My Classes and choose Request Correction next to the candidate.'
                        }
                    />
                ) : (
                    <Table caption="Grade correction requests" className="min-w-[64rem]">
                        <TableHead>
                            <Th>Request</Th>
                            <Th>Candidate</Th>
                            <Th>Assessment</Th>
                            <Th align="right">Score Change</Th>
                            <Th>What Happened</Th>
                            <Th>Status</Th>
                            <Th align="right">
                                <span className="sr-only">Actions</span>
                            </Th>
                        </TableHead>
                        <TableBody>
                            {requests.data.map((request) => (
                                <Tr key={request.id}>
                                    <Td className="whitespace-nowrap text-ink">
                                        <span className="font-medium">#{request.id}</span>
                                        <span className="block text-xs text-ink-muted">
                                            {formatDate.dateTime(request.requestedAt)} · {request.requestedBy}
                                        </span>
                                    </Td>
                                    <Td className="text-ink">
                                        {request.candidate.name}
                                        <span className="block text-xs text-ink-muted">{request.candidate.number}</span>
                                    </Td>
                                    <Td className="text-ink">
                                        {request.assessment.title}
                                        <span className="block text-xs text-ink-muted">
                                            {request.assessment.subject} · {request.assessment.className}
                                        </span>
                                    </Td>
                                    <Td align="right" numeric className="whitespace-nowrap text-ink">
                                        {request.currentScore ?? 'None'} → <strong>{request.proposedScore ?? 'None'}</strong>
                                        <span className="block text-xs text-ink-muted">of {request.assessment.maxScore}</span>
                                    </Td>
                                    <Td className="text-ink">{request.incidentType.label}</Td>
                                    <Td>
                                        <StatusBadge tone={request.status.tone}>{request.status.label}</StatusBadge>
                                    </Td>
                                    <Td align="right">
                                        <RowAction href={routes.gradeCorrections.show(request.id)} label={`Open correction request #${request.id}`}>
                                            {scope === 'all' && request.status.value === 'pending' ? 'Review' : 'Open'}
                                        </RowAction>
                                    </Td>
                                </Tr>
                            ))}
                        </TableBody>
                    </Table>
                )}

                <Pagination page={requests} noun={{ one: 'request', other: 'requests' }} />
            </section>
        </>
    );
}
