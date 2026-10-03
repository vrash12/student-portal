import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AccountCategoryForm, type AccountCategoryFormData } from '@/components/accounts/category-form';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';

export default function CreateAccountCategory() {
    const form = useForm<AccountCategoryFormData>({
        name: '',
        description: '',
        is_active: true,
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        // is_active applies to existing categories only; new ones start active.
        form.transform(({ is_active: _isActive, ...data }) => data);
        form.post(routes.accounts.categories.store(), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Add Account Category" />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title="Add Account Category"
                    description="A category of expenses."
                    breadcrumbs={[
                        { label: 'Expenses', href: routes.accounts.expenses.index() },
                        { label: 'Categories', href: routes.accounts.categories.index() },
                        { label: 'Add Category' },
                    ]}
                />
                <AccountCategoryForm form={form} mode="create" submitLabel="Add Category" onSubmit={submit} />
            </div>
        </>
    );
}
