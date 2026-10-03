import { Head } from '@inertiajs/react';
import { Plus, Tags } from 'lucide-react';
import type { AccountCategoryRow } from '@/components/accounts/types';
import { ButtonLink } from '@/components/ui/button';
import { ClientPagination, useClientPagination } from '@/components/ui/client-pagination';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { routes } from '@/lib/routes';

export default function AccountCategories({ categories }: { categories: AccountCategoryRow[] }) {
    const pagination = useClientPagination(categories);
    const addAction = (
        <ButtonLink href={routes.accounts.categories.create()} variant="primary" icon={<Plus className="size-4" aria-hidden="true" />}>
            Add Category
        </ButtonLink>
    );

    return (
        <>
            <Head title="Account Categories" />

            <PageHeader
                title="Account Categories"
                description="The categories expenses belong to, such as billing, uniforms and meals. Categories in use are deactivated, never deleted."
                breadcrumbs={[{ label: 'Expenses', href: routes.accounts.expenses.index() }, { label: 'Categories' }]}
                actions={addAction}
            />

            <div className="rounded-lg border border-line-box bg-surface">
                {categories.length === 0 ? (
                    <EmptyState
                        icon={Tags}
                        title="No account categories yet"
                        description="Add the categories expenses belong to, such as billing, uniforms and meals."
                        action={addAction}
                    />
                ) : (
                    <Table caption="Account categories" className="min-w-[32rem]">
                        <TableHead>
                            <Th>Category</Th>
                            <Th>Status</Th>
                            <Th align="right">
                                <span className="sr-only">Actions</span>
                            </Th>
                        </TableHead>
                        <TableBody>
                            {pagination.rows.map((category) => (
                                <Tr key={category.id}>
                                    <Td className="text-ink">
                                        <span className="font-medium">{category.name}</span>
                                        {category.description && <span className="block text-xs text-ink-muted">{category.description}</span>}
                                    </Td>
                                    <Td>
                                        {category.isActive ? <StatusBadge tone="success">Active</StatusBadge> : <StatusBadge tone="neutral">Inactive</StatusBadge>}
                                    </Td>
                                    <Td align="right">
                                        <RowAction href={routes.accounts.categories.edit(category.id)} label={`Edit ${category.name}`}>
                                            Edit
                                        </RowAction>
                                    </Td>
                                </Tr>
                            ))}
                        </TableBody>
                    </Table>
                )}
                <ClientPagination pagination={pagination} noun={{ one: 'category', other: 'categories' }} label="Account category pages" />
            </div>
        </>
    );
}
