import { Head } from '@inertiajs/react';
import { Plus, SearchX, UserRoundCog } from 'lucide-react';
import { Button, ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar, SearchField } from '@/components/ui/filter-bar';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { routes } from '@/lib/routes';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { Paginated } from '@/types';

interface InstructorRow {
    id: number;
    name: string;
    username: string;
    isActive: boolean;
    assignmentCount: number;
}

interface InstructorsIndexProps {
    instructors: Paginated<InstructorRow>;
    filters: { search: string; [key: string]: string };
    canCreateAccounts: boolean;
}

export default function InstructorsIndex({ instructors, filters, canCreateAccounts }: InstructorsIndexProps) {
    const { values, update, reset, isFiltered } = useQueryFilters(routes.instructors.index(), filters);

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

            <div className="rounded-lg border border-line bg-surface">
                <FilterBar onReset={reset} canReset={isFiltered}>
                    <SearchField
                        placeholder="Search instructors by name or username…"
                        value={values.search}
                        onChange={(value) => update('search', value, { debounce: true })}
                    />
                </FilterBar>

                {instructors.data.length === 0 ? (
                    isFiltered ? (
                        <EmptyState
                            icon={SearchX}
                            title="No instructors match this search"
                            description="Try a different name or username."
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
