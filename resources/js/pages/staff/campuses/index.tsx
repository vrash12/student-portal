import { Head } from '@inertiajs/react';
import { Building2, Plus } from 'lucide-react';
import type { CampusRow } from '@/components/academic/campus-form';
import { ButtonLink } from '@/components/ui/button';
import { ClientPagination, useClientPagination } from '@/components/ui/client-pagination';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';

interface CampusListRow extends CampusRow {
    classCount: number;
    candidateCount: number;
    staffCount: number;
}

export default function Campuses({ campuses }: { campuses: CampusListRow[] }) {
    const pagination = useClientPagination(campuses);
    const addAction = (
        <ButtonLink href={routes.campuses.create()} variant="primary" icon={<Plus className="size-4" aria-hidden="true" />}>
            Add Campus
        </ButtonLink>
    );

    return (
        <>
            <Head title="Campuses" />

            <PageHeader
                title="Campuses"
                description={`${terms.classBatch.plural}, ${terms.candidate.plural.toLowerCase()} and instructors belong to a campus. Academic years, subjects and settings are shared by every campus. Campuses in use are deactivated, never deleted.`}
                actions={addAction}
            />

            <div className="rounded-lg border border-line-box bg-surface">
                {campuses.length === 0 ? (
                    <EmptyState
                        icon={Building2}
                        title="No campuses yet"
                        description={`Add a campus before creating ${terms.classBatch.plural.toLowerCase()}.`}
                        action={addAction}
                    />
                ) : (
                    <Table caption="Campuses" className="min-w-[40rem]">
                        <TableHead>
                            <Th>Campus</Th>
                            <Th align="right">{terms.classBatch.plural}</Th>
                            <Th align="right">{terms.candidate.plural}</Th>
                            <Th align="right">Active staff</Th>
                            <Th>Status</Th>
                            <Th align="right">
                                <span className="sr-only">Actions</span>
                            </Th>
                        </TableHead>
                        <TableBody>
                            {pagination.rows.map((campus) => (
                                <Tr key={campus.id}>
                                    <Td className="text-ink">
                                        <span className="font-medium">{campus.name}</span>
                                        <span className="block text-xs text-ink-muted">
                                            {campus.code}
                                            {campus.address && ` · ${campus.address}`}
                                        </span>
                                    </Td>
                                    <Td align="right" className="tabular-nums">
                                        {campus.classCount}
                                    </Td>
                                    <Td align="right" className="tabular-nums">
                                        {campus.candidateCount}
                                    </Td>
                                    <Td align="right" className="tabular-nums">
                                        {campus.staffCount}
                                    </Td>
                                    <Td>
                                        {campus.isActive ? <StatusBadge tone="success">Active</StatusBadge> : <StatusBadge tone="neutral">Inactive</StatusBadge>}
                                    </Td>
                                    <Td align="right">
                                        <RowAction href={routes.campuses.edit(campus.id)} label={`Edit ${campus.name}`}>
                                            Edit
                                        </RowAction>
                                    </Td>
                                </Tr>
                            ))}
                        </TableBody>
                    </Table>
                )}
                <ClientPagination pagination={pagination} noun={{ one: 'campus', other: 'campuses' }} label="Campus pages" />
            </div>
        </>
    );
}
