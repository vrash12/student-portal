import { Head } from '@inertiajs/react';
import { Medal, SearchX, Tags } from 'lucide-react';
import { formatNetPoints } from '@/components/conduct/conduct-totals';
import type { ClassOptionGroup } from '@/components/candidates/candidate-form';
import { Button, ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar, SearchField } from '@/components/ui/filter-bar';
import { FormField, SelectInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { Paginated } from '@/types';
import type { ConductCandidateRow, ConductScopeKind } from '@/types/conduct';

interface ConductFilters {
    search: string;
    class: string;
    [key: string]: string;
}

interface ConductIndexProps {
    candidates: Paginated<ConductCandidateRow>;
    filters: ConductFilters;
    /** Classes within the user's scope, active period first. */
    classOptions: ClassOptionGroup[];
    scope: ConductScopeKind;
    can: { configureTypes: boolean };
}

export default function ConductIndex({ candidates, filters, classOptions, scope, can }: ConductIndexProps) {
    const { values, update, reset, isFiltered } = useQueryFilters(routes.conduct.index(), filters);
    const classTerm = terms.classBatch.singular;

    return (
        <>
            <Head title="Merits & Demerits" />

            <PageHeader
                title="Merits & Demerits"
                description={
                    scope === 'all'
                        ? 'Merit and demerit points of every candidate. Open a candidate to record an entry or void a mistaken one.'
                        : `Merit and demerit points of the candidates in the ${terms.classBatch.plural.toLowerCase()} you teach. Open a candidate to record an entry or void a mistaken one.`
                }
                actions={
                    can.configureTypes && (
                        <ButtonLink href={routes.conduct.types.index()} icon={<Tags className="size-4" aria-hidden="true" />}>
                            Merit & Demerit Types
                        </ButtonLink>
                    )
                }
            />

            <div className="flex flex-col gap-6">
                <section className="rounded-lg border border-line bg-surface" aria-label="Candidate merits and demerits">
                    <FilterBar onReset={reset} canReset={isFiltered}>
                        <SearchField
                            placeholder="Search candidates by number or name…"
                            value={values.search}
                            onChange={(value) => update('search', value, { debounce: true })}
                        />
                        <FormField label={classTerm} className="sm:w-56">
                            <SelectInput value={values.class} onChange={(event) => update('class', event.target.value)}>
                                <option value="">{`All ${scope === 'all' ? '' : 'my '}${terms.classBatch.plural.toLowerCase()}`}</option>
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
                        ) : scope === 'all' ? (
                            <EmptyState icon={Medal} title="No candidates yet" description="Merits and demerits can be recorded once candidate records exist." />
                        ) : (
                            <EmptyState
                                icon={Medal}
                                title="No candidates in your classes"
                                description={`Merits and demerits can be recorded for the candidates of the ${terms.classBatch.plural.toLowerCase()} you are assigned to teach. Ask an administrator if an assignment is missing.`}
                            />
                        )
                    ) : (
                        <Table caption="Merit and demerit points of candidates" className="min-w-[50rem]">
                            <TableHead>
                                <Th>Candidate No.</Th>
                                <Th>Name</Th>
                                <Th>{classTerm}</Th>
                                <Th align="right">Merits</Th>
                                <Th align="right">Demerits</Th>
                                <Th align="right">Net</Th>
                                <Th align="right">
                                    <span className="sr-only">Actions</span>
                                </Th>
                            </TableHead>
                            <TableBody>
                                {candidates.data.map((candidate) => (
                                    <Tr key={candidate.id}>
                                        <Td className="whitespace-nowrap font-medium text-ink" numeric>
                                            {candidate.candidateNumber}
                                        </Td>
                                        <Td className="text-ink">
                                            {candidate.name}
                                            {candidate.status.value !== 'enrolled' && (
                                                <span className="block text-xs text-ink-muted">{candidate.status.label}</span>
                                            )}
                                        </Td>
                                        <Td className="whitespace-nowrap text-ink-muted">{candidate.className ?? 'Not assigned'}</Td>
                                        <Td align="right" numeric className="text-ink">
                                            {candidate.totals.merits}
                                        </Td>
                                        <Td align="right" numeric className="text-ink">
                                            {candidate.totals.demerits}
                                        </Td>
                                        <Td align="right" numeric className="font-semibold text-ink">
                                            {formatNetPoints(candidate.totals.net)}
                                        </Td>
                                        <Td align="right">
                                            <RowAction href={routes.conduct.show(candidate.id)} label={`Open the merits and demerits of ${candidate.name}`}>
                                                Open
                                            </RowAction>
                                        </Td>
                                    </Tr>
                                ))}
                            </TableBody>
                        </Table>
                    )}

                    <Pagination page={candidates} noun={{ one: 'candidate', other: 'candidates' }} />
                </section>

                <p className="text-sm text-ink-muted">Points count every entry that has not been voided. Net is merits minus demerits.</p>
            </div>
        </>
    );
}
