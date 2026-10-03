import type { InertiaForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/button';
import { CheckboxField, FormField, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { routes } from '@/lib/routes';

export interface AccountCategoryFormData {
    name: string;
    description: string;
    is_active: boolean;
}

interface AccountCategoryFormProps {
    form: InertiaForm<AccountCategoryFormData>;
    mode: 'create' | 'edit';
    submitLabel: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}

export function AccountCategoryForm({ form, mode, submitLabel, onSubmit }: AccountCategoryFormProps) {
    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-6">
            <FormSection title="Category">
                <FormField label="Name" required error={form.errors.name}>
                    <TextInput
                        name="name"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        maxLength={100}
                        autoComplete="off"
                        placeholder="e.g. Uniforms"
                    />
                </FormField>

                <FormField label="Description" error={form.errors.description} hint="Optional. What the category covers. Up to 255 characters.">
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
