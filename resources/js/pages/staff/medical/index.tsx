import { Head } from '@inertiajs/react';
import { FileDown, FileStack, HeartPulse, Settings2 } from 'lucide-react';
import { CampusFilter } from '@/components/academic/campus-filter';
import { ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar } from '@/components/ui/filter-bar';
import { FormField, SelectInput, TextInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { CampusOption, Paginated } from '@/types';

interface MedicalCandidateRow {
    id: number;
    number: string;
    name: string;
    className: string | null;
    /** Active fields with a value. */
    recorded: number;
    updatedAt: string | null;
    /** Documents the candidate uploaded, and how many wait for review. */
    documents: number;
    waitingDocuments: number;
}

interface MedicalRecordsProps {
    candidates: Paginated<MedicalCandidateRow>;
    /** Active fields of the medical record. */
    fieldCount: number;
    filters: { search: string; campus: string; class: string };
    /** Campuses to filter by (accounts that see every campus only). */
    campusOptions: CampusOption[];
    classes: Array<{ id: number; name: string }>;
    /** Uploaded documents waiting for review. */
    waitingDocuments: number;
    /** Instructors' download requests waiting for a decision. */
    pendingDownloadRequests: number;
    can: { configure: boolean; manage: boolean };
}

/** Candidates and how much of their medical record is filled in. Medical staff only. */
export default function MedicalRecords({ candidates, fieldCount, filters, campusOptions, classes, waitingDocuments, pendingDownloadRequests, can }: MedicalRecordsProps) {
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
                description="Confidential. Candidates upload their medical certificates and check-up findings for review; the record fields set by the administrators complete the record. Every change is kept in the record's history."
                actions={
                    <>
                        {can.manage && (
                            <ButtonLink href={routes.medical.documents.index()} variant={waitingDocuments > 0 ? 'primary' : 'secondary'} icon={<FileStack className="size-4" aria-hidden="true" />}>
                                Documents to Review
                                {waitingDocuments > 0 && (
                                    <span className="ml-1 rounded-full bg-accent-300 px-2 text-xs font-bold text-primary-900 tabular-nums">
                                        {waitingDocuments}
                                        <span className="sr-only"> waiting</span>
                                    </span>
                                )}
                            </ButtonLink>
                        )}
                        {can.manage && (
                            <ButtonLink href={routes.medical.downloads.index()} icon={<FileDown className="size-4" aria-hidden="true" />}>
                                Download Requests
                                {pendingDownloadRequests > 0 && (
                                    <span className="ml-1 rounded-full bg-accent-300 px-2 text-xs font-bold text-primary-900 tabular-nums">
                                        {pendingDownloadRequests}
                                        <span className="sr-only"> waiting</span>
                                    </span>
                                )}
                            </ButtonLink>
                        )}
                        {configureAction}
                    </>
                }
            />

            <section className="rounded-lg border border-line-box bg-surface" aria-label="Candidate medical records">
                <FilterBar onReset={() => updateMany({ search: '', campus: '', class: '' })} canReset={values.search !== '' || values.campus !== '' || values.class !== ''}>
                    <FormField label="Search" className="sm:w-72">
                        <TextInput type="search" value={values.search} onChange={(event) => update('search', event.target.value)} placeholder="Candidate number or name" />
                    </FormField>
                    <CampusFilter options={campusOptions} value={values.campus} onChange={(campus) => updateMany({ campus, class: '' })} />
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
                            <Th>Documents</Th>
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
                                    <Td className="whitespace-nowrap text-ink">
                                        <span className={candidate.documents === 0 ? 'text-ink-muted' : undefined}>{candidate.documents}</span>
                                        {candidate.waitingDocuments > 0 && (
                                            <StatusBadge tone="warning" className="ml-2">
                                                {candidate.waitingDocuments} to review
                                            </StatusBadge>
                                        )}
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
