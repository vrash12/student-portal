import { Head } from '@inertiajs/react';
import { ListCharts, type ListChart } from '@/components/charts/list-charts';
import { GraduationCap, Plus, SearchX } from 'lucide-react';
import type { ClassOptionGroup, StatusOption } from '@/components/candidates/candidate-form';
import { CandidateUnit } from '@/components/candidates/candidate-unit';
import { Button, ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar, SearchField } from '@/components/ui/filter-bar';
import { FormField, SelectInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { Paginated } from '@/types';
import type { CandidateGroupOptions } from '@/types/candidates';

interface CandidateRow {
    id: number;
    candidateNumber: string;
    name: string;
    className: string | null;
    company: string | null;
    platoon: string | null;
    status: { value: string; label: string; tone: StatusTone };
    updatedAt: string | null;
}

interface CandidateFilters {
    search: string;
    class: string;
    status: string;
    company: string;
    platoon: string;
    [key: string]: string;
}

interface CandidatesIndexProps extends CandidateGroupOptions {
    /** Charts computed by the server from the filtered list. */
    charts: ListChart[];
    candidates: Paginated<CandidateRow>;
    filters: CandidateFilters;
    classOptions: ClassOptionGroup[];
    statusOptions: StatusOption[];
    canCreate: boolean;
}

export default function CandidatesIndex({ candidates, filters, classOptions, statusOptions, companyOptions, platoonOptions, canCreate, charts }: CandidatesIndexProps) {
    const { values, update, reset, isFiltered } = useQueryFilters(routes.candidates.index(), filters);
    const formatDate = useDateFormatter();
    const classTerm = terms.classBatch.singular;

    const createAction = canCreate && (
        <ButtonLink href={routes.candidates.create()} variant="primary" icon={<Plus className="size-4" aria-hidden="true" />}>
            Create Candidate
        </ButtonLink>
    );

    return (
        <>
            <Head title="Candidates" />

            <PageHeader
                title="Candidates"
                description={`Candidate records, ${classTerm.toLowerCase()} assignments, and sign-in accounts.`}
                actions={createAction}
            />

            <ListCharts charts={charts} />

            <div className="rounded-lg border border-line bg-surface">
                <FilterBar onReset={reset} canReset={isFiltered}>
                    <SearchField
                        placeholder="Search candidates by number or name…"
                        value={values.search}
                        onChange={(value) => update('search', value, { debounce: true })}
                    />
                    <FormField label={classTerm} className="sm:w-56">
                        <SelectInput value={values.class} onChange={(event) => update('class', event.target.value)}>
                            <option value="">All {terms.classBatch.plural.toLowerCase()}</option>
                            {classOptions.map((group) => (
                                <optgroup key={group.period} label={`${group.period}${group.isActive ? ' (active)' : ''}`}>
                                    {group.classes.map((classBatch) => (
                                        <option key={classBatch.id} value={String(classBatch.id)}>
                                            {classBatch.name}
                                        </option>
                                    ))}
                                </optgroup>
                            ))}
                        </SelectInput>
                    </FormField>
                    <FormField label="Status" className="sm:w-48">
                        <SelectInput value={values.status} onChange={(event) => update('status', event.target.value)}>
                            <option value="">All statuses</option>
                            {statusOptions.map((status) => (
                                <option key={status.value} value={status.value}>
                                    {status.label}
                                </option>
                            ))}
                        </SelectInput>
                    </FormField>
                    {/* Only names already recorded on candidates are offered. */}
                    {companyOptions.length > 0 && (
                        <FormField label="Company" className="sm:w-48">
                            <SelectInput value={values.company} onChange={(event) => update('company', event.target.value)}>
                                <option value="">All companies</option>
                                {companyOptions.map((company) => (
                                    <option key={company} value={company}>
                                        {company}
                                    </option>
                                ))}
                            </SelectInput>
                        </FormField>
                    )}
                    {platoonOptions.length > 0 && (
                        <FormField label="Platoon" className="sm:w-48">
                            <SelectInput value={values.platoon} onChange={(event) => update('platoon', event.target.value)}>
                                <option value="">All platoons</option>
                                {platoonOptions.map((platoon) => (
                                    <option key={platoon} value={platoon}>
                                        {platoon}
                                    </option>
                                ))}
                            </SelectInput>
                        </FormField>
                    )}
                </FilterBar>

                {candidates.data.length === 0 ? (
                    isFiltered ? (
                        <EmptyState
                            icon={SearchX}
                            title="No candidates match these filters"
                            description="Try a different search term, or reset the filters to see every candidate."
                            action={
                                <Button variant="secondary" onClick={reset}>
                                    Reset Filters
                                </Button>
                            }
                        />
                    ) : (
                        <EmptyState
                            icon={GraduationCap}
                            title="No candidates yet"
                            description={`Create candidate records and assign each candidate to a ${classTerm.toLowerCase()}.`}
                            action={createAction || undefined}
                        />
                    )
                ) : (
                    <Table caption="Candidates">
                        <TableHead>
                            <Th>Candidate No.</Th>
                            <Th>Name</Th>
                            <Th>{classTerm}</Th>
                            <Th className="hidden md:table-cell">Company / Platoon</Th>
                            <Th>Status</Th>
                            <Th className="hidden lg:table-cell">Last Updated</Th>
                            <Th align="right">
                                <span className="sr-only">Actions</span>
                            </Th>
                        </TableHead>
                        <TableBody>
                            {candidates.data.map((candidate) => (
                                <Tr key={candidate.id}>
                                    <Td className="font-medium text-ink" numeric>
                                        {candidate.candidateNumber}
                                    </Td>
                                    <Td className="text-ink">{candidate.name}</Td>
                                    <Td className="text-ink-muted">{candidate.className ?? 'Not assigned'}</Td>
                                    <Td className="hidden md:table-cell">
                                        <CandidateUnit company={candidate.company} platoon={candidate.platoon} />
                                    </Td>
                                    <Td>
                                        <StatusBadge tone={candidate.status.tone}>{candidate.status.label}</StatusBadge>
                                    </Td>
                                    <Td className="hidden text-ink-muted lg:table-cell">{formatDate.dateTime(candidate.updatedAt)}</Td>
                                    <Td align="right">
                                        <RowAction href={routes.candidates.show(candidate.id)} label={`View ${candidate.name}`}>
                                            View
                                        </RowAction>
                                    </Td>
                                </Tr>
                            ))}
                        </TableBody>
                    </Table>
                )}

                <Pagination page={candidates} noun={{ one: 'candidate', other: 'candidates' }} />
            </div>
        </>
    );
}
