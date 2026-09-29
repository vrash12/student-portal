import { Head, Link } from '@inertiajs/react';
import { ChevronRight, GraduationCap, SearchX } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar, SearchField } from '@/components/ui/filter-bar';
import { FormField, SelectInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { Paginated } from '@/types';

interface CandidateRow {
    id: number;
    candidateNumber: string;
    name: string;
    status: { value: string; label: string; tone: StatusTone };
}

interface TeachingClassProps {
    classBatch: { id: number; name: string; period: { name: string; isActive: boolean } };
    subjects: Array<{ classSubjectId: number; code: string; name: string }>;
    candidates: Paginated<CandidateRow>;
    filters: { search: string; status: string; [key: string]: string };
    statusOptions: Array<{ value: string; label: string }>;
}

export default function TeachingClass({ classBatch, subjects, candidates, filters, statusOptions }: TeachingClassProps) {
    const { values, update, reset, isFiltered } = useQueryFilters(routes.teaching.classes.show(classBatch.id), filters);
    const { singular, plural } = terms.classBatch;

    return (
        <>
            <Head title={classBatch.name} />

            <PageHeader
                title={classBatch.name}
                description={
                    <>
                        {classBatch.period.name}{' '}
                        {classBatch.period.isActive && <StatusBadge tone="success">Active</StatusBadge>}
                    </>
                }
                breadcrumbs={[{ label: `My ${plural}`, href: routes.teaching.classes.index() }, { label: classBatch.name }]}
            />

            <section aria-labelledby="subjects-you-teach" className="mb-6 rounded-lg border border-line bg-surface px-5 py-4">
                <h2 id="subjects-you-teach" className="text-sm font-semibold text-ink">
                    Subjects You Teach in This {singular}
                </h2>
                <p className="mt-0.5 text-sm text-ink-muted">Open a subject's gradebook to create assessments and record scores.</p>
                <ul className="mt-3 flex flex-wrap gap-2">
                    {subjects.map((subject) => (
                        <li key={subject.classSubjectId}>
                            <Link
                                href={routes.teaching.gradebook(classBatch.id, subject.classSubjectId)}
                                className="inline-flex items-center gap-1.5 rounded-md border border-line-strong bg-surface px-3 py-1.5 text-sm font-medium text-ink hover:bg-surface-muted pointer-coarse:py-2.5"
                            >
                                {subject.name} <span className="font-normal text-ink-muted">({subject.code})</span>
                                <span className="font-normal text-primary-700">Gradebook</span>
                                <ChevronRight className="size-4 text-ink-muted" aria-hidden="true" />
                            </Link>
                        </li>
                    ))}
                </ul>
            </section>

            <div className="rounded-lg border border-line bg-surface">
                <div className="border-b border-line px-5 py-4">
                    <h2 className="text-base font-semibold text-ink">Candidates</h2>
                    <p className="mt-0.5 text-sm text-ink-muted">
                        <span className="tabular-nums">{candidates.total}</span> {candidates.total === 1 ? 'candidate' : 'candidates'}
                        {isFiltered ? ' match these filters.' : ` in this ${singular.toLowerCase()}.`}
                    </p>
                </div>

                <FilterBar onReset={reset} canReset={isFiltered}>
                    <SearchField
                        placeholder="Search candidates by number or name…"
                        value={values.search}
                        onChange={(value) => update('search', value, { debounce: true })}
                    />
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
                </FilterBar>

                {candidates.data.length === 0 ? (
                    isFiltered ? (
                        <EmptyState
                            icon={SearchX}
                            headingLevel="h3"
                            title="No candidates match these filters"
                            description="Try a different search term, or reset the filters."
                            action={
                                <Button variant="secondary" onClick={reset}>
                                    Reset Filters
                                </Button>
                            }
                        />
                    ) : (
                        <EmptyState
                            icon={GraduationCap}
                            headingLevel="h3"
                            title={`No candidates in this ${singular.toLowerCase()} yet`}
                            description={`Candidates appear here once an administrator assigns them to this ${singular.toLowerCase()}.`}
                        />
                    )
                ) : (
                    <Table caption={`Candidates in ${classBatch.name}`} className="min-w-[32rem]">
                        <TableHead>
                            <Th>Candidate No.</Th>
                            <Th>Name</Th>
                            <Th>Status</Th>
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
                                    <Td>
                                        <StatusBadge tone={candidate.status.tone}>{candidate.status.label}</StatusBadge>
                                    </Td>
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
