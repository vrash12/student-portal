import type { InertiaForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import type { AccountEntryTypeOption } from '@/components/accounts/types';
import { Button, ButtonLink } from '@/components/ui/button';
import { CheckboxField, FormField, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { RadioCards } from '@/components/ui/radio-cards';
import { routes } from '@/lib/routes';

export interface AccountCategoryFormData {
    name: string;
    entry_type: string;
    description: string;
    sort_order: string;
    is_active: boolean;
}

interface AccountCategoryFormProps {
    form: InertiaForm<AccountCategoryFormData>;
    mode: 'create' | 'edit';
    entryTypes: AccountEntryTypeOption[];
    submitLabel: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}

export function AccountCategoryForm({ form, mode, entryTypes, submitLabel, onSubmit }: AccountCategoryFormProps) {
    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-6">
            <FormSection title="Category">
                <div className="grid gap-5 sm:grid-cols-4">
                    <FormField label="Name" required error={form.errors.name} className="sm:col-span-3">
                        <TextInput
                            name="name"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            maxLength={100}
                            autoComplete="off"
                            placeholder="e.g. Uniforms"
                        />
                    </FormField>
                    <FormField label="Order" required error={form.errors.sort_order} hint="Position in lists.">
                        <TextInput
                            name="sort_order"
                            value={form.data.sort_order}
                            onChange={(event) => form.setData('sort_order', event.target.value)}
                            inputMode="numeric"
                            autoComplete="off"
                        />
                    </FormField>
                </div>

                <FormField label="Description" error={form.errors.description} hint="Optional. What the category covers. Up to 255 characters.">
                    <TextInput
                        name="description"
                        value={form.data.description}
                        onChange={(event) => form.setData('description', event.target.value)}
                        maxLength={255}
                        autoComplete="off"
                    />
                </FormField>

                <RadioCards
                    legend="Usual type of entry"
                    name="entry_type"
                    options={entryTypes}
                    value={form.data.entry_type}
                    onChange={(value) => form.setData('entry_type', value)}
                    error={form.errors.entry_type}
                    required
                />
                <p className="text-sm text-ink-muted">The usual type is preselected when recording an entry in this category; it can still be changed for each entry, for example to refund a charge.</p>

                {mode === 'edit' && (
                    <CheckboxField
                        label="Category is active"
                        description="Inactive categories keep their recorded entries but cannot be used for new entries."
                        checked={form.data.is_active}
                        onChange={(event) => form.setData('is_active', event.target.checked)}
                        error={form.errors.is_active}
                    />
                )}
            </FormSection>

            <FormActions>
                <ButtonLink href={routes.accounts.categories.index()} variant="secondary">
                    Cancel
                </ButtonLink>
                <Button type="submit" loading={form.processing}>
                    {submitLabel}
                </Button>
            </FormActions>
        </form>
    );
}
