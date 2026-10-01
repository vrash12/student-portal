import { Head, Link } from '@inertiajs/react';
import { BookOpen, Plus, SearchX } from 'lucide-react';
import { Button, ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar, SearchField } from '@/components/ui/filter-bar';
import { FormField, SelectInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { Paginated } from '@/types';

interface SubjectRow {
    id: number;
    code: string;
    name: string;
    isActive: boolean;
    classCount: number;
    /** Classes taking the subject in the active period, with their instructors. */
    taughtIn: { classId: number; className: string; instructors: string[] }[];
}

interface SubjectFilters {
    search: string;
    status: string;
    [key: string]: string;
}

interface SubjectsIndexProps {
    subjects: Paginated<SubjectRow>;
    filters: SubjectFilters;
    activePeriod: { id: number; name: string } | null;
}

export default function SubjectsIndex({ subjects, filters, activePeriod }: SubjectsIndexProps) {
    const { values, update, reset, isFiltered } = useQueryFilters(routes.subjects.index(), filters);

    const createAction = (
        <ButtonLink href={routes.subjects.create()} variant="primary" icon={<Plus className="size-4" aria-hidden="true" />}>
            Create Subject
        </ButtonLink>
    );

    return (
        <>
            <Head title="Subjects" />

            <PageHeader title="Subjects" description={`Subjects that ${terms.classBatch.plural.toLowerCase()} can take. Deactivate subjects that are no longer offered.`} actions={createAction} />

            <div className="rounded-lg border border-line bg-surface">
                <FilterBar onReset={reset} canReset={isFiltered}>
                    <SearchField
                        placeholder="Search subjects by code or name…"
                        value={values.search}
                        onChange={(value) => update('search', value, { debounce: true })}
                    />
                    <FormField label="Status" className="sm:w-48">
                        <SelectInput value={values.status} onChange={(event) => update('status', event.target.value)}>
                            <option value="">All statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </SelectInput>
                    </FormField>
                </FilterBar>

                {subjects.data.length === 0 ? (
                    isFiltered ? (
                        <EmptyState
                            icon={SearchX}
                            title="No subjects match these filters"
                            description="Try a different search term, or reset the filters."
                            action={
                                <Button variant="secondary" onClick={reset}>
                                    Reset Filters
                                </Button>
                            }
                        />
                    ) : (
                        <EmptyState
                            icon={BookOpen}
                            title="No subjects yet"
                            description={`Create the subjects that ${terms.classBatch.plural.toLowerCase()} will take.`}
                            action={createAction}
                        />
                    )
                ) : (
                    <Table caption="Subjects">
                        <TableHead>
                            <Th>Code</Th>
                            <Th>Name</Th>
                            <Th>Status</Th>
                            <Th>{activePeriod === null ? 'Taught In' : `Taught In ${activePeriod.name}`}</Th>
                            <Th align="right">All {terms.classBatch.plural}</Th>
                            <Th align="right">
                                <span className="sr-only">Actions</span>
                            </Th>
                        </TableHead>
                        <TableBody>
                            {subjects.data.map((subject) => (
                                <Tr key={subject.id}>
                                    <Td className="font-medium text-ink">{subject.code}</Td>
                                    <Td className="text-ink">{subject.name}</Td>
                                    <Td>
                                        {subject.isActive ? (
                                            <StatusBadge tone="success">Active</StatusBadge>
                                        ) : (
                                            <StatusBadge tone="neutral">Inactive</StatusBadge>
                                        )}
                                    </Td>
                                    <Td>
                                        {subject.taughtIn.length === 0 ? (
                                            <span className="text-ink-muted">{activePeriod === null ? 'No active period' : 'Not offered this period'}</span>
                                        ) : (
                                            <ul className="flex flex-col gap-1">
                                                {subject.taughtIn.map((offering) => (
                                                    <li key={offering.classId}>
                                                        <Link href={routes.classes.show(offering.classId)} className="font-medium text-primary-700 underline">
                                                            {offering.className}
                                                        </Link>
                                                        <span className="text-ink-muted"> · </span>
                                                        {offering.instructors.length > 0 ? (
                                                            <span className="text-ink">{offering.instructors.join(', ')}</span>
                                                        ) : (
                                                            <StatusBadge tone="warning">No instructor</StatusBadge>
                                                        )}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </Td>
                                    <Td align="right" numeric>
                                        {subject.classCount}
                                    </Td>
                                    <Td align="right">
                                        <RowAction href={routes.subjects.edit(subject.id)} label={`Edit ${subject.name}`}>
                                            Edit
                                        </RowAction>
                                    </Td>
                                </Tr>
                            ))}
                        </TableBody>
                    </Table>
                )}

                <Pagination page={subjects} noun={{ one: 'subject', other: 'subjects' }} />
            </div>
        </>
    );
}
