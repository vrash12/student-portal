import type { InertiaForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/button';
import { CheckboxField, FormField, TextArea, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { routes } from '@/lib/routes';

export interface SubjectFormData {
    code: string;
    name: string;
    description: string;
    is_active: boolean;
}

interface SubjectFormProps {
    form: InertiaForm<SubjectFormData>;
    mode: 'create' | 'edit';
    submitLabel: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}

export function SubjectForm({ form, mode, submitLabel, onSubmit }: SubjectFormProps) {
    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-6">
            <FormSection title="Subject Details">
                <div className="grid gap-5 sm:grid-cols-3">
                    <FormField label="Code" required error={form.errors.code} hint="Letters, numbers, periods, hyphens, underscores.">
                        <TextInput
                            name="code"
                            value={form.data.code}
                            onChange={(event) => form.setData('code', event.target.value)}
                            maxLength={30}
                            autoComplete="off"
                            autoCapitalize="characters"
                            spellCheck={false}
                        />
                    </FormField>
                    <FormField label="Name" required error={form.errors.name} className="sm:col-span-2">
                        <TextInput
                            name="name"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            maxLength={150}
                            autoComplete="off"
                        />
                    </FormField>
                </div>

                <FormField label="Description" error={form.errors.description} hint="Optional. Up to 500 characters.">
                    <TextArea
                        name="description"
                        value={form.data.description}
                        onChange={(event) => form.setData('description', event.target.value)}
                        maxLength={500}
                        rows={3}
                    />
                </FormField>

                {mode === 'edit' && (
                    <CheckboxField
                        label="Subject is active"
                        description="Inactive subjects keep their history but cannot be added to classes."
                        checked={form.data.is_active}
                        onChange={(event) => form.setData('is_active', event.target.checked)}
                        error={form.errors.is_active}
                    />
                )}
            </FormSection>

            <FormActions>
                <ButtonLink href={routes.subjects.index()} variant="secondary">
                    Cancel
                </ButtonLink>
                <Button type="submit" loading={form.processing}>
                    {submitLabel}
                </Button>
            </FormActions>
        </form>
    );
}
