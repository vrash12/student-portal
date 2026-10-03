import { Head } from '@inertiajs/react';
import { Plus, SearchX, UserRoundCog } from 'lucide-react';
import { CampusFilter, showsCampuses } from '@/components/academic/campus-filter';
import { Button, ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar, SearchField } from '@/components/ui/filter-bar';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { routes } from '@/lib/routes';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { CampusOption, CampusSummary, Paginated } from '@/types';

interface InstructorRow {
    id: number;
    name: string;
    username: string;
    isActive: boolean;
    campus: CampusSummary | null;
    assignmentCount: number;
}

interface InstructorsIndexProps {
    instructors: Paginated<InstructorRow>;
    filters: { search: string; campus: string; [key: string]: string };
    /** Campuses to filter by (accounts that see every campus only). */
    campusOptions: CampusOption[];
    canCreateAccounts: boolean;
}

export default function InstructorsIndex({ instructors, filters, campusOptions, canCreateAccounts }: InstructorsIndexProps) {
    const { values, update, reset, isFiltered } = useQueryFilters(routes.instructors.index(), filters);
    const showCampus = showsCampuses(campusOptions);

    const createAction = canCreateAccounts && (
        <ButtonLink href={routes.users.create()} variant="primary" icon={<Plus className="size-4" aria-hidden="true" />}>
            Create Instructor Account
        </ButtonLink>
    );

    return (
        <>
            <Head title="Instructors" />

            <PageHeader
                title="Instructors"
                description="Teaching staff and the subjects they are assigned to. Accounts are created in Users with the Instructor role."
                actions={createAction}
            />

            <div className="rounded-lg border border-line-box bg-surface">
                <FilterBar onReset={reset} canReset={isFiltered}>
                    <SearchField
                        placeholder="Search instructors by name or username…"
                        value={values.search}
                        onChange={(value) => update('search', value, { debounce: true })}
                    />
                    <CampusFilter options={campusOptions} value={values.campus} onChange={(campus) => update('campus', campus)} />
                </FilterBar>

                {instructors.data.length === 0 ? (
                    isFiltered ? (
                        <EmptyState
                            icon={SearchX}
                            title="No instructors match these filters"
                            description="Try a different name or username, or reset the filters."
                            action={
                                <Button variant="secondary" onClick={reset}>
                                    Reset Filters
                                </Button>
                            }
                        />
                    ) : (
                        <EmptyState
                            icon={UserRoundCog}
                            title="No instructors yet"
                            description="Create an account with the Instructor role, then assign subjects from a class page."
                            action={createAction || undefined}
                        />
                    )
                ) : (
                    <Table caption="Instructors">
                        <TableHead>
                            <Th>Name</Th>
                            {showCampus && <Th>Campus</Th>}
                            <Th>Status</Th>
                            <Th align="right">Assignments</Th>
                            <Th align="right">
                                <span className="sr-only">Actions</span>
                            </Th>
                        </TableHead>
                        <TableBody>
                            {instructors.data.map((instructor) => (
                                <Tr key={instructor.id}>
                                    <Td>
                                        <p className="font-medium text-ink">{instructor.name}</p>
                                        <p className="text-ink-muted">{instructor.username}</p>
                                    </Td>
                                    {showCampus && <Td className="text-ink-muted">{instructor.campus?.name ?? 'No campus'}</Td>}
                                    <Td>
                                        {instructor.isActive ? (
                                            <StatusBadge tone="success">Active</StatusBadge>
                                        ) : (
                                            <StatusBadge tone="neutral">Deactivated</StatusBadge>
                                        )}
                                    </Td>
                                    <Td align="right" numeric>
                                        {instructor.assignmentCount}
                                    </Td>
                                    <Td align="right">
                                        <RowAction href={routes.instructors.show(instructor.id)} label={`View ${instructor.name}`}>
                                            View
                                        </RowAction>
                                    </Td>
                                </Tr>
                            ))}
                        </TableBody>
                    </Table>
                )}

                <Pagination page={instructors} noun={{ one: 'instructor', other: 'instructors' }} />
            </div>
        </>
    );
}
