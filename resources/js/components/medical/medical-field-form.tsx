import type { InertiaForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/button';
import { CheckboxField, FormField, TextArea, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { RadioCards } from '@/components/ui/radio-cards';
import { routes } from '@/lib/routes';
import type { MedicalFieldTypeOption } from '@/types/medical';

export interface MedicalFieldFormData {
    name: string;
    field_type: string;
    /** Choices, one per line (choice fields only). */
    options: string;
    help_text: string;
    sort_order: string;
    visible_to_instructors: boolean;
    visible_to_candidate: boolean;
    is_active: boolean;
}

interface MedicalFieldFormProps {
    form: InertiaForm<MedicalFieldFormData>;
    mode: 'create' | 'edit';
    types: MedicalFieldTypeOption[];
    /** Values are recorded: the type can no longer change. */
    typeLocked: boolean;
    submitLabel: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}

/** Name, kind of answer, choices and audience of a medical record field. */
export function MedicalFieldForm({ form, mode, types, typeLocked, submitLabel, onSubmit }: MedicalFieldFormProps) {
    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-6">
            <FormSection title="Field">
                <FormField label="Name" required error={form.errors.name}>
                    <TextInput
                        name="name"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        maxLength={100}
                        autoComplete="off"
                        placeholder="e.g. Allergies"
                    />
                </FormField>

                <RadioCards
                    legend="Kind of answer"
                    name="field_type"
                    options={types}
                    value={form.data.field_type}
                    onChange={(value) => form.setData('field_type', value)}
                    error={form.errors.field_type}
                    required
                    disabled={typeLocked}
                />
                {typeLocked && <p className="-mt-3 text-sm text-ink-muted">The kind of answer cannot change because values are recorded.</p>}

                {form.data.field_type === 'choice' && (
                    <FormField
                        label="Choices"
                        required
                        error={form.errors.options}
                        hint="One per line, in the order they are offered. At least two. A choice still recorded for a candidate cannot be removed."
                    >
                        <TextArea
                            name="options"
                            value={form.data.options}
                            onChange={(event) => form.setData('options', event.target.value)}
                            rows={6}
                            placeholder={'A+\nA−\nB+\nB−'}
                        />
                    </FormField>
                )}

                <div className="grid gap-5 sm:grid-cols-[minmax(0,1fr)_10rem]">
                    <FormField label="Help text" error={form.errors.help_text} hint="Optional. Shown under the field when recording. Up to 255 characters.">
                        <TextInput
                            name="help_text"
                            value={form.data.help_text}
                            onChange={(event) => form.setData('help_text', event.target.value)}
                            maxLength={255}
                            autoComplete="off"
                        />
                    </FormField>
                    <FormField label="Order" required error={form.errors.sort_order} hint="0 to 999.">
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
            </FormSection>

            <FormSection title="Who Sees This Field" description="Administrators with the medical permissions always see every field.">
                <CheckboxField
                    label="Instructors of the candidate's classes"
                    description="Only for safety information instructors need in training, such as allergies or emergency notes."
                    checked={form.data.visible_to_instructors}
                    onChange={(event) => form.setData('visible_to_instructors', event.target.checked)}
                    error={form.errors.visible_to_instructors}
                />
                <CheckboxField
                    label="The candidate (own record, in the portal)"
                    description="The candidate sees their own value under My Information, read-only."
                    checked={form.data.visible_to_candidate}
                    onChange={(event) => form.setData('visible_to_candidate', event.target.checked)}
                    error={form.errors.visible_to_candidate}
                />
                {mode === 'edit' && (
                    <CheckboxField
                        label="Field is active"
                        description="Inactive fields are hidden from records and forms; their values and history are kept."
                        checked={form.data.is_active}
                        onChange={(event) => form.setData('is_active', event.target.checked)}
                        error={form.errors.is_active}
                    />
                )}
            </FormSection>

            <FormActions>
                <ButtonLink href={routes.medical.fields.index()} variant="secondary">
                    Cancel
                </ButtonLink>
                <Button type="submit" loading={form.processing}>
                    {submitLabel}
                </Button>
            </FormActions>
        </form>
    );
}
