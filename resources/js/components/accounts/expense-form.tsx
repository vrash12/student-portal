import type { InertiaForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/button';
import { CheckboxField, FormField, SelectInput, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';

export interface AccountExpenseFormData {
    name: string;
    account_category_id: string;
    amount: string;
    description: string;
    is_active: boolean;
}

interface AccountExpenseFormProps {
    form: InertiaForm<AccountExpenseFormData>;
    mode: 'create' | 'edit';
    /** Charge categories the expense can belong to. */
    categories: Array<{ id: number; name: string }>;
    /** Once charged to candidates, the amount and category are fixed (the server enforces it too). */
    locked: boolean;
    cancelHref: string;
    submitLabel: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}

export function AccountExpenseForm({ form, mode, categories, locked, cancelHref, submitLabel, onSubmit }: AccountExpenseFormProps) {
    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-6">
            <FormSection title="Expense" description="Defined once, then assigned to a whole class or to chosen candidates. Each candidate is charged the amount below.">
                <FormField label="Name" required error={form.errors.name} hint="Shown on each charge.">
                    <TextInput
                        name="name"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        maxLength={150}
                        autoComplete="off"
                        placeholder="e.g. Uniform set, Meals for September"
                    />
                </FormField>

                <div className="grid gap-5 sm:grid-cols-2">
                    <FormField label="Category" required error={form.errors.account_category_id} className="sm:col-span-1">
                        <SelectInput
                            name="account_category_id"
                            value={form.data.account_category_id}
                            onChange={(event) => form.setData('account_category_id', event.target.value)}
                            disabled={locked}
                        >
                            <option value="">Choose a category</option>
                            {categories.map((category) => (
                                <option key={category.id} value={String(category.id)}>
                                    {category.name}
                                </option>
                            ))}
                        </SelectInput>
                    </FormField>
                    <FormField label="Amount per candidate" required error={form.errors.amount} hint="e.g. 3500 or 3,500.00">
                        <TextInput
                            name="amount"
                            value={form.data.amount}
                            onChange={(event) => form.setData('amount', event.target.value)}
                            inputMode="decimal"
                            autoComplete="off"
                            className="tabular-nums"
                            disabled={locked}
                        />
                    </FormField>
                </div>

                <FormField label="Description" error={form.errors.description} hint="Optional. For staff; up to 255 characters.">
                    <TextInput
                        name="description"
                        value={form.data.description}
                        onChange={(event) => form.setData('description', event.target.value)}
                        maxLength={255}
                        autoComplete="off"
                    />
                </FormField>

                {mode === 'edit' && (
                    <CheckboxField
                        label="Expense is active"
                        description="Inactive expenses keep their charges but cannot be assigned again."
                        checked={form.data.is_active}
                        onChange={(event) => form.setData('is_active', event.target.checked)}
                        error={form.errors.is_active}
                    />
                )}
            </FormSection>

            <FormActions>
                <ButtonLink href={cancelHref} variant="secondary">
                    Cancel
                </ButtonLink>
                <Button type="submit" loading={form.processing}>
                    {submitLabel}
                </Button>
            </FormActions>
        </form>
    );
}
