import { Head, Link } from '@inertiajs/react';
import { ClipboardCheck } from 'lucide-react';
import { ButtonLink, buttonClasses } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { examinationRoutes } from '@/lib/examination-routes';
import { useDateFormatter } from '@/lib/format';
import type { Paginated } from '@/types';

interface Attempt {
    id: number;
    number: number;
    candidate: string;
    candidateNumber: string;
    submittedAt: string | null;
    status: string;
    percentage: string | null;
}

interface ExaminationGradingProps {
    examination: { id: number; title: string };
    attempts: Paginated<Attempt>;
    status?: string;
}

const statusFilters = [
    { value: 'pending', label: 'Pending Review' },
    { value: 'graded', label: 'Completed' },
] as const;

const emptyStates: Record<string, { title: string; description: string }> = {
    pending: {
        title: 'No essays awaiting review',
        description: 'Submitted attempts with essay answers appear here until every essay has a score.',
    },
    graded: {
        title: 'No graded submissions yet',
        description: 'Attempts appear here once all of their essays have been scored.',
    },
};

export default function ExaminationGrading({ examination, attempts, status = 'pending' }: ExaminationGradingProps) {
    const { dateTime } = useDateFormatter();
    const empty = emptyStates[status] ?? { title: 'No submissions yet', description: 'Submitted attempts appear here.' };

    return (
        <>
            <Head title={`Essay Grading · ${examination.title}`} />
            <PageHeader
                title="Essay Grading"
                description={`${examination.title} · review subjective responses and record scores.`}
                breadcrumbs={[
                    { label: 'Examinations', href: examinationRoutes.index() },
                    { label: examination.title, href: examinationRoutes.show(examination.id) },
                    { label: 'Essay Grading' },
                ]}
                actions={<ButtonLink href={examinationRoutes.show(examination.id)}>Back to Examination</ButtonLink>}
            />

            <nav aria-label="Grading status" className="mb-4">
                <ul className="flex flex-wrap gap-2">
                    {statusFilters.map((filter) => {
                        const active = filter.value === status;

                        return (
                            <li key={filter.value}>
                                <Link
                                    href={`${examinationRoutes.grading(examination.id)}?status=${filter.value}`}
                                    aria-current={active ? 'page' : undefined}
                                    className={buttonClasses(active ? 'primary' : 'secondary', 'md')}
                                >
                                    {filter.label}
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </nav>

            <section aria-label="Submissions" className="rounded-xl border border-line bg-surface">
                {attempts.data.length === 0 ? (
                    <EmptyState icon={ClipboardCheck} title={empty.title} description={empty.description} />
                ) : (
                    <Table caption="Examination submissions" className="min-w-[44rem]">
                        <TableHead>
                            <Th>Candidate</Th>
                            <Th align="right">Attempt</Th>
                            <Th>Submitted</Th>
                            <Th>Status</Th>
                            <Th align="right">Result</Th>
                            <Th>
                                <span className="sr-only">Actions</span>
                            </Th>
                        </TableHead>
                        <TableBody>
                            {attempts.data.map((attempt) => {
                                const pending = attempt.status === 'pending_review';

                                return (
                                    <Tr key={attempt.id}>
                                        <Td>
                                            <p className="font-medium">{attempt.candidate}</p>
                                            <p className="text-xs text-ink-muted">{attempt.candidateNumber}</p>
                                        </Td>
                                        <Td numeric align="right">
                                            {attempt.number}
                                        </Td>
                                        <Td>{dateTime(attempt.submittedAt)}</Td>
                                        <Td>
                                            <StatusBadge tone={pending ? 'warning' : 'success'}>{pending ? 'Pending review' : 'Graded'}</StatusBadge>
                                        </Td>
                                        <Td numeric align="right">
                                            {!pending && attempt.percentage !== null ? `${attempt.percentage}%` : '—'}
                                        </Td>
                                        <Td align="right">
                                            <ButtonLink
                                                href={examinationRoutes.gradeAttempt(attempt.id)}
                                                size="sm"
                                                aria-label={`Open response of ${attempt.candidate} (${attempt.candidateNumber}), attempt ${attempt.number}`}
                                            >
                                                Open Response
                                            </ButtonLink>
                                        </Td>
                                    </Tr>
                                );
                            })}
                        </TableBody>
                    </Table>
                )}
                <Pagination page={attempts} noun={{ one: 'submission', other: 'submissions' }} />
            </section>
        </>
    );
}
