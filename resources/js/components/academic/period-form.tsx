import type { InertiaForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/button';
import { FormField, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { routes } from '@/lib/routes';

export interface PeriodFormData {
    name: string;
    starts_on: string;
    ends_on: string;
}

interface PeriodFormProps {
    form: InertiaForm<PeriodFormData>;
    submitLabel: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}

export function PeriodForm({ form, submitLabel, onSubmit }: PeriodFormProps) {
    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-6">
            <FormSection title="Period Details" description="Use a name that staff will recognize, such as the term or cycle.">
                <FormField label="Name" required error={form.errors.name}>
                    <TextInput
                        name="name"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        maxLength={100}
                        autoComplete="off"
                    />
                </FormField>

                <div className="grid gap-5 sm:grid-cols-2">
                    <FormField label="Start Date" required error={form.errors.starts_on}>
                        <TextInput
                            type="date"
                            name="starts_on"
                            value={form.data.starts_on}
                            onChange={(event) => form.setData('starts_on', event.target.value)}
                        />
                    </FormField>
                    <FormField label="End Date" required error={form.errors.ends_on}>
                        <TextInput
                            type="date"
                            name="ends_on"
                            value={form.data.ends_on}
                            min={form.data.starts_on || undefined}
                            onChange={(event) => form.setData('ends_on', event.target.value)}
                        />
                    </FormField>
                </div>
            </FormSection>

            <FormActions>
                <ButtonLink href={routes.academicPeriods.index()} variant="secondary">
                    Cancel
                </ButtonLink>
                <Button type="submit" loading={form.processing}>
                    {submitLabel}
                </Button>
            </FormActions>
        </form>
    );
}
