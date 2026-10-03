import type { InertiaForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/button';
import { CheckboxField, FormField, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { routes } from '@/lib/routes';

export interface CampusFormData {
    name: string;
    code: string;
    address: string;
    is_active: boolean;
}

/** A campus as listed and edited on the Campuses pages. */
export interface CampusRow {
    id: number;
    name: string;
    code: string;
    address: string | null;
    isActive: boolean;
}

interface CampusFormProps {
    form: InertiaForm<CampusFormData>;
    mode: 'create' | 'edit';
    submitLabel: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}

export function CampusForm({ form, mode, submitLabel, onSubmit }: CampusFormProps) {
    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-6">
            <FormSection title="Campus">
                <FormField label="Name" required error={form.errors.name}>
                    <TextInput
                        name="name"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        maxLength={100}
                        autoComplete="off"
                        placeholder="e.g. North Campus"
                    />
                </FormField>

                <FormField label="Code" required error={form.errors.code} hint="A short code shown in lists, such as NORTH. Letters, numbers, hyphens and underscores.">
                    <TextInput
                        name="code"
                        value={form.data.code}
                        onChange={(event) => form.setData('code', event.target.value.toUpperCase())}
                        maxLength={20}
                        autoComplete="off"
                        className="uppercase"
                    />
                </FormField>

                <FormField label="Address" error={form.errors.address} hint="Optional. Up to 255 characters.">
                    <TextInput
                        name="address"
                        value={form.data.address}
                        onChange={(event) => form.setData('address', event.target.value)}
                        maxLength={255}
                        autoComplete="off"
                    />
                </FormField>

                {mode === 'edit' && (
                    <CheckboxField
                        label="Campus is active"
                        description="New classes, candidates and staff can only be placed on an active campus. Existing records stay where they are."
                        checked={form.data.is_active}
                        onChange={(event) => form.setData('is_active', event.target.checked)}
                        error={form.errors.is_active}
                    />
                )}
            </FormSection>

            <FormActions>
                <ButtonLink href={routes.campuses.index()} variant="secondary">
                    Cancel
                </ButtonLink>
                <Button type="submit" loading={form.processing}>
                    {submitLabel}
                </Button>
            </FormActions>
        </form>
    );
}
