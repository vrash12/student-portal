import { Head } from '@inertiajs/react';
import { HeartPulse, LockKeyhole, Settings2 } from 'lucide-react';
import { ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar } from '@/components/ui/filter-bar';
import { FormField, SelectInput, TextInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { Paginated } from '@/types';

interface MedicalCandidateRow {
    id: number;
    number: string;
    name: string;
    className: string | null;
    /** Active fields with a value. */
    recorded: number;
    updatedAt: string | null;
}

interface MedicalRecordsProps {
    candidates: Paginated<MedicalCandidateRow>;
    /** Active fields of the medical record. */
    fieldCount: number;
    filters: { search: string; class: string };
    classes: Array<{ id: number; name: string }>;
    /** Instructors' requests to see a full record, waiting for a decision. */
    pendingAccessRequests: number;
    can: { configure: boolean; manage: boolean };
}

/** Candidates and how much of their medical record is filled in. Medical staff only. */
export default function MedicalRecords({ candidates, fieldCount, filters, classes, pendingAccessRequests, can }: MedicalRecordsProps) {
    const formatDate = useDateFormatter();
    const { values, update, updateMany } = useQueryFilters(routes.medical.records.index(), filters);
    const configureAction = can.configure && (
        <ButtonLink href={routes.medical.fields.index()} icon={<Settings2 className="size-4" aria-hidden="true" />}>
            Configure Fields
        </ButtonLink>
    );

    return (
        <>
            <Head title="Medical Records" />

            <PageHeader
                title="Medical Records"
                description="Confidential. Each candidate's medical record answers the fields set by the administrators. Every change is kept in the record's history."
                actions={
                    <>
                        {can.manage && (
                            <ButtonLink href={routes.medical.access.index()} icon={<LockKeyhole className="size-4" aria-hidden="true" />}>
                                Access Requests
                                {pendingAccessRequests > 0 && (
                                    <span className="ml-1 rounded-full bg-accent-300 px-2 text-xs font-bold text-primary-900 tabular-nums">
                                        {pendingAccessRequests}
                                        <span className="sr-only"> waiting</span>
                                    </span>
                                )}
                            </ButtonLink>
                        )}
                        {configureAction}
                    </>
                }
            />

            <section className="rounded-lg border border-line bg-surface" aria-label="Candidate medical records">
                <FilterBar onReset={() => updateMany({ search: '', class: '' })} canReset={values.search !== '' || values.class !== ''}>
                    <FormField label="Search" className="sm:w-72">
                        <TextInput type="search" value={values.search} onChange={(event) => update('search', event.target.value)} placeholder="Candidate number or name" />
                    </FormField>
                    <FormField label={terms.classBatch.singular} className="sm:w-60">
                        <SelectInput value={values.class} onChange={(event) => update('class', event.target.value)}>
                            <option value="">All {terms.classBatch.plural.toLowerCase()}</option>
                            {classes.map((classBatch) => (
                                <option key={classBatch.id} value={String(classBatch.id)}>
                                    {classBatch.name}
                                </option>
                            ))}
                        </SelectInput>
                    </FormField>
                </FilterBar>

                {fieldCount === 0 ? (
                    <EmptyState
                        icon={HeartPulse}
                        title="The medical record has no fields yet"
                        description={can.configure ? 'Add the fields first, such as blood type, allergies and existing conditions.' : 'An administrator must add the fields of the medical record first.'}
                        action={configureAction || undefined}
                    />
                ) : candidates.data.length === 0 ? (
                    <EmptyState icon={HeartPulse} title="No candidates found" description="No candidate matches the search or the selected class." />
                ) : (
                    <Table caption="Candidate medical records" className="min-w-[48rem]">
                        <TableHead>
                            <Th>Candidate</Th>
                            <Th>{terms.classBatch.singular}</Th>
                            <Th align="right">Fields Recorded</Th>
                            <Th>Last Updated</Th>
                            <Th align="right">
                                <span className="sr-only">Actions</span>
                            </Th>
                        </TableHead>
                        <TableBody>
                            {candidates.data.map((candidate) => (
                                <Tr key={candidate.id}>
                                    <Td className="text-ink">
                                        <span className="font-medium">{candidate.name}</span>
                                        <span className="block text-xs text-ink-muted">{candidate.number}</span>
                                    </Td>
                                    <Td className="text-ink">{candidate.className ?? '—'}</Td>
                                    <Td align="right" numeric className={candidate.recorded === 0 ? 'text-ink-muted' : 'text-ink'}>
                                        {candidate.recorded} of {fieldCount}
                                    </Td>
                                    <Td className="whitespace-nowrap text-ink">{candidate.updatedAt === null ? 'Never' : formatDate.dateTime(candidate.updatedAt)}</Td>
                                    <Td align="right">
                                        <span className="inline-flex gap-1">
                                            <RowAction href={routes.candidates.show(candidate.id)} label={`Open the profile of ${candidate.name}`}>
                                                Profile
                                            </RowAction>
                                            {can.manage && (
                                                <RowAction href={routes.medical.records.edit(candidate.id)} label={`Edit the medical record of ${candidate.name}`}>
                                                    Edit Record
                                                </RowAction>
                                            )}
                                        </span>
                                    </Td>
                                </Tr>
                            ))}
                        </TableBody>
                    </Table>
                )}

                <Pagination page={candidates} noun={{ one: 'candidate', other: 'candidates' }} />
            </section>
        </>
    );
}
