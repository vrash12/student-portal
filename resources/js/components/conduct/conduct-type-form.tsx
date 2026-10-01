import type { InertiaForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/button';
import { CheckboxField, FormField, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { RadioCards } from '@/components/ui/radio-cards';
import { routes } from '@/lib/routes';
import type { ConductKindOption } from '@/types/conduct';

export interface ConductTypeFormData {
    name: string;
    kind: string;
    default_points: string;
    description: string;
    sort_order: string;
    is_active: boolean;
}

interface ConductTypeFormProps {
    form: InertiaForm<ConductTypeFormData>;
    mode: 'create' | 'edit';
    kinds: ConductKindOption[];
    submitLabel: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}

/** Name, kind, usual points and order of a merit or demerit type. */
export function ConductTypeForm({ form, mode, kinds, submitLabel, onSubmit }: ConductTypeFormProps) {
    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-6">
            <FormSection title="Type">
                <FormField label="Name" required error={form.errors.name}>
                    <TextInput
                        name="name"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        maxLength={100}
                        autoComplete="off"
                        placeholder="e.g. Late for formation"
                    />
                </FormField>

                <RadioCards
                    legend="Kind"
                    name="kind"
                    options={kinds}
                    value={form.data.kind}
                    onChange={(value) => form.setData('kind', value)}
                    error={form.errors.kind}
                    required
                />

                <div className="grid gap-5 sm:grid-cols-2">
                    <FormField
                        label="Usual points"
                        required
                        error={form.errors.default_points}
                        hint="1 to 100. Filled in when recording; can be adjusted for each entry."
                    >
                        <TextInput
                            name="default_points"
                            value={form.data.default_points}
                            onChange={(event) => form.setData('default_points', event.target.value)}
                            inputMode="numeric"
                            autoComplete="off"
                            className="tabular-nums"
                        />
                    </FormField>
                    <FormField label="Order" required error={form.errors.sort_order} hint="Position in lists, 0 to 999.">
                        <TextInput
                            name="sort_order"
                            value={form.data.sort_order}
                            onChange={(event) => form.setData('sort_order', event.target.value)}
                            inputMode="numeric"
                            autoComplete="off"
                            className="tabular-nums"
                        />
                    </FormField>
                </div>

                <FormField label="Description" error={form.errors.description} hint="Optional. When to use this type. Up to 255 characters.">
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
                        label="Type is active"
                        description="Inactive types keep their recorded entries but cannot be used for new entries."
                        checked={form.data.is_active}
                        onChange={(event) => form.setData('is_active', event.target.checked)}
                        error={form.errors.is_active}
                    />
                )}
            </FormSection>

            <FormActions>
                <ButtonLink href={routes.conduct.types.index()} variant="secondary">
                    Cancel
                </ButtonLink>
                <Button type="submit" loading={form.processing}>
                    {submitLabel}
                </Button>
            </FormActions>
        </form>
    );
}
