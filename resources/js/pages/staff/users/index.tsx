import { Head, Link } from '@inertiajs/react';
import { Plus, Search, UserRoundX, Users as UsersIcon } from 'lucide-react';
import { Button, ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField, SelectInput, TextInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { StatusBadge } from '@/components/ui/status-badge';
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
    users: Paginated<UserRow>;
    filters: UserFilters;
    roles: Array<{ code: string; name: string }>;
    canCreate: boolean;
}

export default function UsersIndex({ users, filters, roles, canCreate }: UsersIndexProps) {
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

            <div className="rounded-lg border border-line bg-surface">
                <div className="grid gap-4 border-b border-line p-4 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
                    <FormField label="Search">
                        <div className="relative">
                            <Search
                                className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-subtle"
                                aria-hidden="true"
                            />
                            <TextInput
                                type="search"
                                value={values.search}
                                onChange={(event) => update('search', event.target.value, { debounce: true })}
                                placeholder="Search accounts by name or username…"
                                className="pl-9"
                                maxLength={100}
                            />
                        </div>
                    </FormField>

                    <FormField label="Role">
                        <SelectInput value={values.role} onChange={(event) => update('role', event.target.value)}>
                            <option value="">All roles</option>
                            {roles.map((role) => (
                                <option key={role.code} value={role.code}>
                                    {role.name}
                                </option>
                            ))}
                        </SelectInput>
                    </FormField>

                    <FormField label="Status">
                        <SelectInput value={values.status} onChange={(event) => update('status', event.target.value)}>
                            <option value="">All statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Deactivated</option>
                        </SelectInput>
                    </FormField>

                    <Button variant="ghost" onClick={reset} disabled={!isFiltered}>
                        Reset Filters
                    </Button>
                </div>

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
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[40rem] text-left text-sm">
                            <thead className="bg-surface-muted text-xs font-semibold uppercase tracking-wide text-ink-muted">
                                <tr>
                                    <th scope="col" className="px-4 py-3">
                                        Name
                                    </th>
                                    <th scope="col" className="px-4 py-3">
                                        Role
                                    </th>
                                    <th scope="col" className="px-4 py-3">
                                        Status
                                    </th>
                                    <th scope="col" className="hidden px-4 py-3 md:table-cell">
                                        Last Sign-In
                                    </th>
                                    <th scope="col" className="px-4 py-3 text-right">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {users.data.map((user) => (
                                    <tr key={user.id} className="hover:bg-surface-muted">
                                        <td className="px-4 py-3">
                                            <p className="font-medium text-ink">{user.name}</p>
                                            <p className="text-ink-muted">{user.username}</p>
                                        </td>
                                        <td className="px-4 py-3 text-ink">{user.role}</td>
                                        <td className="px-4 py-3">
                                            {user.isActive ? (
                                                <StatusBadge tone="success">Active</StatusBadge>
                                            ) : (
                                                <StatusBadge tone="neutral">Deactivated</StatusBadge>
                                            )}
                                        </td>
                                        <td className="hidden px-4 py-3 text-ink-muted md:table-cell">
                                            {user.lastLoginAt ? formatDate.dateTime(user.lastLoginAt) : 'Never'}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {user.canEdit && (
                                                <Link
                                                    href={routes.users.edit(user.id)}
                                                    className="inline-flex h-9 items-center rounded-md px-3 font-medium text-primary-700 hover:bg-primary-50 pointer-coarse:h-11"
                                                    aria-label={`Edit ${user.name}`}
                                                >
                                                    Edit
                                                </Link>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <Pagination page={users} noun={{ one: 'account', other: 'accounts' }} />
            </div>
        </>
    );
}
