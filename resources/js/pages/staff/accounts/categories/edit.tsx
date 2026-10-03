import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AccountCategoryForm, type AccountCategoryFormData } from '@/components/accounts/category-form';
import type { AccountCategoryRow } from '@/components/accounts/types';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';

interface EditAccountCategoryProps {
    category: AccountCategoryRow;
}

export default function EditAccountCategory({ category }: EditAccountCategoryProps) {
    const form = useForm<AccountCategoryFormData>({
        name: category.name,
        description: category.description ?? '',
        is_active: category.isActive,
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put(routes.accounts.categories.update(category.id), { preserveScroll: true });
    };

    return (
        <>
            <Head title={`Edit ${category.name}`} />

            <div className="mx-auto flex max-w-3xl flex-col gap-6">
                <PageHeader
                    title={category.name}
                    breadcrumbs={[
                        { label: 'Expenses', href: routes.accounts.expenses.index() },
                        { label: 'Categories', href: routes.accounts.categories.index() },
                        { label: category.name },
                    ]}
                />
                <AccountCategoryForm form={form} mode="edit" submitLabel="Save Changes" onSubmit={submit} />
            </div>
        </>
    );
}
