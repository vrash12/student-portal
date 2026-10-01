import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AccountExpenseForm, type AccountExpenseFormData } from '@/components/accounts/expense-form';
import { Alert } from '@/components/ui/alert';
import { ButtonLink } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';

interface CreateAccountExpenseProps {
    /** Active charge categories. */
    categories: Array<{ id: number; name: string }>;
}

export default function CreateAccountExpense({ categories }: CreateAccountExpenseProps) {
    const form = useForm<AccountExpenseFormData>({
        name: '',
        account_category_id: '',
        amount: '',
        due_on: '',
        description: '',
        is_active: true,
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        // is_active applies to existing expenses only; new ones start active.
        form.transform(({ is_active: _isActive, ...data }) => data);
        form.post(routes.accounts.expenses.store(), { preserveScroll: true });
    };

    return (
        <>
            <Head title="New Expense" />

            <div className="mx-auto flex max-w-3xl flex-col gap-6">
                <PageHeader
                    title="New Expense"
                    description="After saving, assign it to a whole class or to chosen candidates."
                    breadcrumbs={[
                        { label: 'Expenses', href: routes.accounts.expenses.index() },
                        { label: 'New Expense' },
                    ]}
                />
                {categories.length === 0 ? (
                    <Alert tone="warning" title="No active charge categories">
                        <p>An expense belongs to a category whose entries are charges. Add or reactivate one first.</p>
                        <div className="mt-2">
                            <ButtonLink href={routes.accounts.categories.index()} variant="secondary" size="sm">
                                Open Account Categories
                            </ButtonLink>
                        </div>
                    </Alert>
                ) : (
                    <AccountExpenseForm
                        form={form}
                        mode="create"
                        categories={categories}
                        locked={false}
                        cancelHref={routes.accounts.expenses.index()}
                        submitLabel="Save Expense"
                        onSubmit={submit}
                    />
                )}
            </div>
        </>
    );
}
