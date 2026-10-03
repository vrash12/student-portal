import { Head } from '@inertiajs/react';
import { Plus, Tags } from 'lucide-react';
import { formatPoints } from '@/components/conduct/conduct-totals';
import { ButtonLink } from '@/components/ui/button';
import { ClientPagination, useClientPagination } from '@/components/ui/client-pagination';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { routes } from '@/lib/routes';
import type { ConductTypeRow } from '@/types/conduct';

export default function ConductTypes({ types }: { types: ConductTypeRow[] }) {
    const pagination = useClientPagination(types);
    const addAction = (
        <ButtonLink href={routes.conduct.types.create()} variant="primary" icon={<Plus className="size-4" aria-hidden="true" />}>
            Add Type
        </ButtonLink>
    );

    return (
        <>
            <Head title="Merit & Demerit Types" />

            <PageHeader
                title="Merit & Demerit Types"
                description="The kinds of merits and demerits that can be recorded, with their usual points. The values are placeholders until the institution confirms its conduct rules. Types in use are deactivated, never deleted."
                breadcrumbs={[{ label: 'Merits & Demerits', href: routes.conduct.index() }, { label: 'Types' }]}
                actions={types.length > 0 && addAction}
            />

            <div className="rounded-lg border border-line-box bg-surface">
                {types.length === 0 ? (
                    <EmptyState
                        icon={Tags}
                        title="No merit or demerit types yet"
                        description="Add the types entries are recorded under, such as a leadership commendation or being late for formation."
                        action={addAction}
                    />
                ) : (
                    <Table caption="Merit and demerit types" className="min-w-[48rem]">
                        <TableHead>
                            <Th>Kind</Th>
                            <Th align="right">Order</Th>
                            <Th>Type</Th>
                            <Th align="right">Usual Points</Th>
                            <Th>Status</Th>
                            <Th align="right">Entries</Th>
                            <Th align="right">
                                <span className="sr-only">Actions</span>
                            </Th>
                        </TableHead>
                        <TableBody>
                            {pagination.rows.map((type) => (
                                <Tr key={type.id}>
                                    <Td>
                                        <StatusBadge tone={type.kind.tone}>{type.kind.label}</StatusBadge>
                                    </Td>
                                    <Td align="right" numeric>
                                        {type.sortOrder}
                                    </Td>
                                    <Td className="text-ink">
                                        <span className="font-medium">{type.name}</span>
                                        {type.description && <span className="block text-xs text-ink-muted">{type.description}</span>}
                                    </Td>
                                    <Td align="right" numeric className="whitespace-nowrap text-ink">
                                        {formatPoints(type.defaultPoints)}
                                    </Td>
                                    <Td>{type.isActive ? <StatusBadge tone="success">Active</StatusBadge> : <StatusBadge tone="neutral">Inactive</StatusBadge>}</Td>
                                    <Td align="right" numeric>
                                        {type.entryCount}
                                    </Td>
                                    <Td align="right">
                                        <RowAction href={routes.conduct.types.edit(type.id)} label={`Edit ${type.name}`}>
                                            Edit
                                        </RowAction>
                                    </Td>
                                </Tr>
                            ))}
                        </TableBody>
                    </Table>
                )}
                <ClientPagination pagination={pagination} noun={{ one: 'type', other: 'types' }} label="Merit and demerit type pages" />
            </div>
        </>
    );
}
