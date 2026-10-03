import { Head } from '@inertiajs/react';
import { ClipboardPlus, Plus } from 'lucide-react';
import { Audience } from '@/components/medical/medical-record-panel';
import { ButtonLink } from '@/components/ui/button';
import { ClientPagination, useClientPagination } from '@/components/ui/client-pagination';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { routes } from '@/lib/routes';
import type { MedicalFieldDefinition } from '@/types/medical';

type FieldRow = MedicalFieldDefinition & { valueCount: number };

/** The fields every candidate's medical record has, as set by administrators. */
export default function MedicalFields({ fields }: { fields: FieldRow[] }) {
    const pagination = useClientPagination(fields);
    const addAction = (
        <ButtonLink href={routes.medical.fields.create()} variant="primary" icon={<Plus className="size-4" aria-hidden="true" />}>
            Add Field
        </ButtonLink>
    );

    return (
        <>
            <Head title="Medical Record Fields" />

            <PageHeader
                title="Medical Record Fields"
                description="The questions every candidate's medical record answers, in order. Choose for each field whether the candidate sees it. Instructors of the candidate's class see the whole record, view only. Fields in use are deactivated, never deleted."
                breadcrumbs={[{ label: 'Medical Records', href: routes.medical.records.index() }, { label: 'Fields' }]}
                actions={fields.length > 0 && addAction}
            />

            <div className="rounded-lg border border-line-box bg-surface">
                {fields.length === 0 ? (
                    <EmptyState
                        icon={ClipboardPlus}
                        title="No medical record fields yet"
                        description="Add the fields of the medical record, such as blood type, allergies, existing conditions or the last physical examination."
                        action={addAction}
                    />
                ) : (
                    <Table caption="Medical record fields" className="min-w-[52rem]">
                        <TableHead>
                            <Th align="right">Order</Th>
                            <Th>Section</Th>
                            <Th>Field</Th>
                            <Th>Kind of Answer</Th>
                            <Th>Also Seen By</Th>
                            <Th>Status</Th>
                            <Th align="right">Recorded</Th>
                            <Th align="right">
                                <span className="sr-only">Actions</span>
                            </Th>
                        </TableHead>
                        <TableBody>
                            {pagination.rows.map((field) => (
                                <Tr key={field.id}>
                                    <Td align="right" numeric>
                                        {field.sortOrder}
                                    </Td>
                                    <Td className="text-ink">{field.section ?? '—'}</Td>
                                    <Td className="text-ink">
                                        <span className="font-medium">{field.name}</span>
                                        {field.helpText && <span className="block text-xs text-ink-muted">{field.helpText}</span>}
                                    </Td>
                                    <Td className="text-ink">
                                        {field.type.label}
                                        {field.unit && <span className="text-ink-muted"> ({field.unit})</span>}
                                        {field.type.value === 'choice' && <span className="block text-xs text-ink-muted">{field.options.join(' · ')}</span>}
                                    </Td>
                                    <Td>
                                        <span className="flex flex-wrap gap-1.5">
                                            {field.visibleToCandidate ? <span className="text-sm text-ink">Candidate (own record)</span> : <Audience candidate={false} />}
                                        </span>
                                    </Td>
                                    <Td>{field.isActive ? <StatusBadge tone="success">Active</StatusBadge> : <StatusBadge tone="neutral">Inactive</StatusBadge>}</Td>
                                    <Td align="right" numeric>
                                        {field.valueCount}
                                    </Td>
                                    <Td align="right">
                                        <RowAction href={routes.medical.fields.edit(field.id)} label={`Edit ${field.name}`}>
                                            Edit
                                        </RowAction>
                                    </Td>
                                </Tr>
                            ))}
                        </TableBody>
                    </Table>
                )}
                <ClientPagination pagination={pagination} noun={{ one: 'field', other: 'fields' }} label="Medical record field pages" />
            </div>
        </>
    );
}
