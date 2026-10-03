import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AccountExpenseForm, type AccountExpenseFormData } from '@/components/accounts/expense-form';
import type { AccountExpense } from '@/components/accounts/types';
import { Alert } from '@/components/ui/alert';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';

interface EditAccountExpenseProps {
    expense: AccountExpense & { isAssigned: boolean };
    categories: Array<{ id: number; name: string }>;
}

export default function EditAccountExpense({ expense, categories }: EditAccountExpenseProps) {
    const form = useForm<AccountExpenseFormData>({
        name: expense.name,
        account_category_id: String(expense.category.id),
        amount: expense.amount,
        description: expense.description ?? '',
        is_active: expense.isActive,
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put(routes.accounts.expenses.update(expense.id), { preserveScroll: true });
    };

    return (
        <>
            <Head title={`Edit ${expense.name}`} />

            <div className="mx-auto flex max-w-3xl flex-col gap-6">
                <PageHeader
                    title={`Edit ${expense.name}`}
                    breadcrumbs={[
                        { label: 'Expenses', href: routes.accounts.expenses.index() },
                        { label: expense.name, href: routes.accounts.expenses.show(expense.id) },
                        { label: 'Edit' },
                    ]}
                />
                {expense.isAssigned && (
                    <Alert title="Already charged to candidates">
                        The amount and category are fixed because candidates are charged this expense. A new name applies to every charge of it. To change the amount, void the charges and create a new expense.
                    </Alert>
                )}
                <AccountExpenseForm
                    form={form}
                    mode="edit"
                    categories={categories}
                    locked={expense.isAssigned}
                    cancelHref={routes.accounts.expenses.show(expense.id)}
                    submitLabel="Save Changes"
                    onSubmit={submit}
                />
            </div>
        </>
    );
}
