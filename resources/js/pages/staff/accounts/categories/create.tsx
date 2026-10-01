import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AccountCategoryForm, type AccountCategoryFormData } from '@/components/accounts/category-form';
import type { AccountEntryTypeOption } from '@/components/accounts/types';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';

interface CreateAccountCategoryProps {
    entryTypes: AccountEntryTypeOption[];
    nextSortOrder: number;
}

export default function CreateAccountCategory({ entryTypes, nextSortOrder }: CreateAccountCategoryProps) {
    const form = useForm<AccountCategoryFormData>({
        name: '',
        entry_type: 'charge',
        description: '',
        sort_order: String(nextSortOrder),
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
                    description="A category of charges or credits on statements of account."
                    breadcrumbs={[
                        { label: 'Statements of Account', href: routes.accounts.index() },
                        { label: 'Categories', href: routes.accounts.categories.index() },
                        { label: 'Add Category' },
                    ]}
                />
                <AccountCategoryForm form={form} mode="create" entryTypes={entryTypes} submitLabel="Add Category" onSubmit={submit} />
            </div>
        </>
    );
}
