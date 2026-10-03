import type { InertiaForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/button';
import { CheckboxField, FormField, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { routes } from '@/lib/routes';

/** What may change on one of the four fixed campuses (owner decision 2026-10-04). */
export interface CampusFormData {
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
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}

/**
 * The address and the on/off switch of a campus. The name and code of the
 * four campuses are fixed, so the page header shows them as text.
 */
export function CampusForm({ form, onSubmit }: CampusFormProps) {
    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-6">
            <FormSection title="Campus Details">
                <FormField label="Address" error={form.errors.address} hint="Optional. Up to 255 characters.">
                    <TextInput
                        name="address"
                        value={form.data.address}
                        onChange={(event) => form.setData('address', event.target.value)}
                        maxLength={255}
                        autoComplete="off"
                    />
                </FormField>

                <CheckboxField
                    label="Campus is active"
                    description="New classes, candidates and staff can only be placed on an active campus. Existing records stay where they are."
                    checked={form.data.is_active}
                    onChange={(event) => form.setData('is_active', event.target.checked)}
                    error={form.errors.is_active}
                />
            </FormSection>

            <FormActions>
                <ButtonLink href={routes.campuses.index()} variant="secondary">
                    Cancel
                </ButtonLink>
                <Button type="submit" loading={form.processing}>
                    Save Changes
                </Button>
            </FormActions>
        </form>
    );
}
