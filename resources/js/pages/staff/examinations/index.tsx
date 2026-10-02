import { Head, Link } from '@inertiajs/react';
import { ListCharts, type ListChart } from '@/components/charts/list-charts';
import { ClipboardList, Plus } from 'lucide-react';
import { ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { examinationRoutes } from '@/lib/examination-routes';
import { terms } from '@/lib/terminology';
import type { Paginated } from '@/types';

interface Exam {
    id: number;
    title: string;
    kind: string;
    lifecycle: { value: string; label: string; tone: StatusTone };
    class_subject: { subject: { name: string }; class_batch: { name: string } };
    duration_minutes: number | null;
}

const kindLabels: Record<string, string> = { examination: 'Examination', quiz: 'Quiz' };

export default function ExaminationIndex({ examinations, charts }: { examinations: Paginated<Exam>; charts: ListChart[] }) {
    const createAction = (
        <ButtonLink href={examinationRoutes.create()} variant="primary" icon={<Plus className="size-4" aria-hidden="true" />}>
            Create Examination
        </ButtonLink>
    );

    return (
        <>
            <Head title="Examinations" />
            <PageHeader
                title="Quizzes & Examinations"
                description="Build, review, and monitor assessments for your assigned subjects."
                actions={createAction}
            />

            <ListCharts charts={charts} />

            <section aria-label="Examinations" className="rounded-xl border border-line-box bg-surface">
                {examinations.total === 0 ? (
                    <EmptyState
                        icon={ClipboardList}
                        title="No examinations yet"
                        description="Create a draft quiz or examination for a subject you teach, then add questions and publish it."
                        action={createAction}
                    />
                ) : (
                    <Table caption="Quizzes and examinations">
                        <TableHead>
                            <Th>Title</Th>
                            <Th>{`${terms.classBatch.singular} / Subject`}</Th>
                            <Th align="right">Time limit</Th>
                            <Th>Status</Th>
                        </TableHead>
                        <TableBody>
                            {examinations.data.map((exam) => (
                                <Tr key={exam.id}>
                                    <Td>
                                        <Link className="font-medium text-primary-700 underline" href={examinationRoutes.show(exam.id)}>
                                            {exam.title}
                                        </Link>
                                        <p className="text-xs text-ink-muted">{kindLabels[exam.kind] ?? exam.kind}</p>
                                    </Td>
                                    <Td>
                                        {exam.class_subject.class_batch.name}
                                        <span aria-hidden="true"> · </span>
                                        <span className="sr-only">, </span>
                                        {exam.class_subject.subject.name}
                                    </Td>
                                    <Td numeric align="right">
                                        {exam.duration_minutes ? `${exam.duration_minutes} min` : 'Not set'}
                                    </Td>
                                    <Td>
                                        <StatusBadge tone={exam.lifecycle.tone}>{exam.lifecycle.label}</StatusBadge>
                                    </Td>
                                </Tr>
                            ))}
                        </TableBody>
                    </Table>
                )}
                <Pagination page={examinations} noun={{ one: 'assessment', other: 'assessments' }} />
            </section>
        </>
    );
}
