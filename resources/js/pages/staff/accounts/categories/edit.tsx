import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AccountCategoryForm, type AccountCategoryFormData } from '@/components/accounts/category-form';
import type { AccountCategoryRow, AccountEntryTypeOption } from '@/components/accounts/types';
import { Alert } from '@/components/ui/alert';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';

interface EditAccountCategoryProps {
    category: AccountCategoryRow;
    entryTypes: AccountEntryTypeOption[];
}

export default function EditAccountCategory({ category, entryTypes }: EditAccountCategoryProps) {
    const form = useForm<AccountCategoryFormData>({
        name: category.name,
        entry_type: category.entryType.value,
        description: category.description ?? '',
        sort_order: String(category.sortOrder),
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
                    description={`Used by ${category.entryCount} ${category.entryCount === 1 ? 'entry' : 'entries'}`}
                    breadcrumbs={[
                        { label: 'Statements of Account', href: routes.accounts.index() },
                        { label: 'Categories', href: routes.accounts.categories.index() },
                        { label: category.name },
                    ]}
                />
                {category.entryCount > 0 && (
                    <Alert title="Recorded entries keep their type and amount">
                        Renaming the category changes how its {category.entryCount} recorded {category.entryCount === 1 ? 'entry is' : 'entries are'} labelled.
                        Changing the usual type only affects new entries.
                    </Alert>
                )}
                <AccountCategoryForm form={form} mode="edit" entryTypes={entryTypes} submitLabel="Save Changes" onSubmit={submit} />
            </div>
        </>
    );
}
