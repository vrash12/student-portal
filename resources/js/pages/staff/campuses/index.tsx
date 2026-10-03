import { Head } from '@inertiajs/react';
import type { CampusRow } from '@/components/academic/campus-form';
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

/** The institution's four fixed campuses (owner decision 2026-10-04): South, North, East and West. */
export default function Campuses({ campuses }: { campuses: CampusListRow[] }) {
    return (
        <>
            <Head title="Campuses" />

            <PageHeader
                title="Campuses"
                description={`${terms.classBatch.plural}, ${terms.candidate.plural.toLowerCase()} and instructors belong to a campus. Academic years, subjects and settings are shared by every campus. The four campuses are fixed: none is added or removed, but a campus can be switched off.`}
            />

            <div className="rounded-lg border border-line-box bg-surface">
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
                        {campuses.map((campus) => (
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
                                <Td>{campus.isActive ? <StatusBadge tone="success">Active</StatusBadge> : <StatusBadge tone="neutral">Inactive</StatusBadge>}</Td>
                                <Td align="right">
                                    <RowAction href={routes.campuses.edit(campus.id)} label={`Edit ${campus.name}`}>
                                        Edit
                                    </RowAction>
                                </Td>
                            </Tr>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </>
    );
}
