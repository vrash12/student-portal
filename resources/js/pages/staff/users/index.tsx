import { Head } from '@inertiajs/react';
import { ListCharts, type ListChart } from '@/components/charts/list-charts';
import { Plus, UserRoundX, Users as UsersIcon } from 'lucide-react';
import { Button, ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar, SearchField } from '@/components/ui/filter-bar';
import { FormField, SelectInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { Paginated } from '@/types';

interface UserRow {
    id: number;
    name: string;
    username: string;
    role: string;
    isActive: boolean;
    lastLoginAt: string | null;
    canEdit: boolean;
}

interface UserFilters {
    search: string;
    role: string;
    status: string;
    [key: string]: string;
}

interface UsersIndexProps {
    /** Charts computed by the server from the filtered list. */
    charts: ListChart[];
    users: Paginated<UserRow>;
    filters: UserFilters;
    roles: Array<{ code: string; name: string }>;
    canCreate: boolean;
}

export default function UsersIndex({ users, filters, roles, canCreate, charts }: UsersIndexProps) {
    const { values, update, reset, isFiltered } = useQueryFilters(routes.users.index(), filters);
    const formatDate = useDateFormatter();

    return (
        <>
            <Head title="Users" />

            <PageHeader
                title="Users"
                description="Staff accounts for administrators and instructors. Candidate accounts are managed with candidate records."
                actions={
                    canCreate && (
                        <ButtonLink href={routes.users.create()} variant="primary" icon={<Plus className="size-4" aria-hidden="true" />}>
                            Create Account
                        </ButtonLink>
                    )
                }
            />

            <ListCharts charts={charts} />

            <div className="rounded-lg border border-line bg-surface">
                <FilterBar onReset={reset} canReset={isFiltered}>
                    <SearchField
                        placeholder="Search accounts by name or username…"
                        value={values.search}
                        onChange={(value) => update('search', value, { debounce: true })}
                    />

                    <FormField label="Role" className="sm:w-52">
                        <SelectInput value={values.role} onChange={(event) => update('role', event.target.value)}>
                            <option value="">All roles</option>
                            {roles.map((role) => (
                                <option key={role.code} value={role.code}>
                                    {role.name}
                                </option>
                            ))}
                        </SelectInput>
                    </FormField>

                    <FormField label="Status" className="sm:w-44">
                        <SelectInput value={values.status} onChange={(event) => update('status', event.target.value)}>
                            <option value="">All statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Deactivated</option>
                        </SelectInput>
                    </FormField>
                </FilterBar>

                {users.data.length === 0 ? (
                    isFiltered ? (
                        <EmptyState
                            icon={UserRoundX}
                            title="No accounts match these filters"
                            description="Try a different search term, or reset the filters to see every staff account."
                            action={
                                <Button variant="secondary" onClick={reset}>
                                    Reset Filters
                                </Button>
                            }
                        />
                    ) : (
                        <EmptyState
                            icon={UsersIcon}
                            title="No staff accounts yet"
                            description="Create an account for each administrator and instructor who needs access."
                        />
                    )
                ) : (
                    <Table caption="Staff accounts">
                        <TableHead>
                            <Th>Name</Th>
                            <Th>Role</Th>
                            <Th>Status</Th>
                            <Th className="hidden md:table-cell">Last Sign-In</Th>
                            <Th align="right">
                                <span className="sr-only">Actions</span>
                            </Th>
                        </TableHead>
                        <TableBody>
                            {users.data.map((user) => (
                                <Tr key={user.id}>
                                    <Td>
                                        <p className="font-medium text-ink">{user.name}</p>
                                        <p className="text-ink-muted">{user.username}</p>
                                    </Td>
                                    <Td className="text-ink">{user.role}</Td>
                                    <Td>
                                        {user.isActive ? (
                                            <StatusBadge tone="success">Active</StatusBadge>
                                        ) : (
                                            <StatusBadge tone="neutral">Deactivated</StatusBadge>
                                        )}
                                    </Td>
                                    <Td className="hidden text-ink-muted md:table-cell">
                                        {user.lastLoginAt ? formatDate.dateTime(user.lastLoginAt) : 'Never'}
                                    </Td>
                                    <Td align="right">
                                        {user.canEdit && (
                                            <RowAction href={routes.users.edit(user.id)} label={`Edit ${user.name}`}>
                                                Edit
                                            </RowAction>
                                        )}
                                    </Td>
                                </Tr>
                            ))}
                        </TableBody>
                    </Table>
                )}

                <Pagination page={users} noun={{ one: 'account', other: 'accounts' }} />
            </div>
        </>
    );
}
