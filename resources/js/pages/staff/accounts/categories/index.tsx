import { Head } from '@inertiajs/react';
import { Plus, Tags } from 'lucide-react';
import type { AccountCategoryRow } from '@/components/accounts/types';
import { ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { routes } from '@/lib/routes';

export default function AccountCategories({ categories }: { categories: AccountCategoryRow[] }) {
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
                description="The categories of statement of account entries, such as billing, uniforms, meals and allowances. Categories in use are deactivated, never deleted."
                breadcrumbs={[{ label: 'Statements of Account', href: routes.accounts.index() }, { label: 'Categories' }]}
                actions={addAction}
            />

            <div className="rounded-lg border border-line bg-surface">
                {categories.length === 0 ? (
                    <EmptyState
                        icon={Tags}
                        title="No account categories yet"
                        description="Add the categories entries are recorded under, such as billing, uniforms, meals, allowances and payments received."
                        action={addAction}
                    />
                ) : (
                    <Table caption="Account categories" className="min-w-[44rem]">
                        <TableHead>
                            <Th align="right">Order</Th>
                            <Th>Category</Th>
                            <Th>Usual Type</Th>
                            <Th>Status</Th>
                            <Th align="right">Entries</Th>
                            <Th align="right">
                                <span className="sr-only">Actions</span>
                            </Th>
                        </TableHead>
                        <TableBody>
                            {categories.map((category) => (
                                <Tr key={category.id}>
                                    <Td align="right" numeric>
                                        {category.sortOrder}
                                    </Td>
                                    <Td className="text-ink">
                                        <span className="font-medium">{category.name}</span>
                                        {category.description && <span className="block text-xs text-ink-muted">{category.description}</span>}
                                    </Td>
                                    <Td className="text-ink">{category.entryType.label}</Td>
                                    <Td>
                                        {category.isActive ? <StatusBadge tone="success">Active</StatusBadge> : <StatusBadge tone="neutral">Inactive</StatusBadge>}
                                    </Td>
                                    <Td align="right" numeric>
                                        {category.entryCount}
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
            </div>
        </>
    );
}
