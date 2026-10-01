import type { InertiaForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/button';
import { CheckboxField, FormField, TextArea, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { RadioCards } from '@/components/ui/radio-cards';
import { routes } from '@/lib/routes';

export interface FitnessEventFormData {
    name: string;
    description: string;
    unit: string;
    higher_is_better: boolean;
    passing_value: string;
    maximum_value: string;
    sort_order: string;
    is_active: boolean;
}

interface FitnessEventFormProps {
    form: InertiaForm<FitnessEventFormData>;
    mode: 'create' | 'edit';
    unitOptions: Array<{ value: string; label: string }>;
    submitLabel: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}

const directionOptions = [
    { value: 'higher', label: 'Higher is better', description: 'More repetitions or a longer hold score more points.' },
    { value: 'lower', label: 'Lower is better', description: 'A faster (shorter) time scores more points, as in a timed run.' },
];

export function FitnessEventForm({ form, mode, unitOptions, submitLabel, onSubmit }: FitnessEventFormProps) {
    const isTime = form.data.unit === 'time';
    const valueHint = isTime ? 'Minutes:seconds, such as 15:30.' : 'Whole number of repetitions.';

    const changeUnit = (unit: string) => {
        // Times are usually better when lower (a run); repetitions when higher.
        form.setData((current) => ({ ...current, unit, higher_is_better: unit !== 'time' }));
    };

    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-6">
            <FormSection title="Event">
                <div className="grid gap-5 sm:grid-cols-4">
                    <FormField label="Name" required error={form.errors.name} className="sm:col-span-3">
                        <TextInput
                            name="name"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            maxLength={100}
                            autoComplete="off"
                            placeholder="e.g. Push-ups (2 minutes)"
                        />
                    </FormField>
                    <FormField label="Order" required error={form.errors.sort_order} hint="Position in tests.">
                        <TextInput
                            name="sort_order"
                            value={form.data.sort_order}
                            onChange={(event) => form.setData('sort_order', event.target.value)}
                            inputMode="numeric"
                            autoComplete="off"
                        />
                    </FormField>
                </div>

                <FormField label="Description" error={form.errors.description} hint="Optional. How the event is done or timed. Up to 500 characters.">
                    <TextArea
                        name="description"
                        value={form.data.description}
                        onChange={(event) => form.setData('description', event.target.value)}
                        maxLength={500}
                        rows={2}
                    />
                </FormField>

                <RadioCards legend="Measured as" name="unit" options={unitOptions} value={form.data.unit} onChange={changeUnit} error={form.errors.unit} required />
                <RadioCards
                    legend="Better results are"
                    name="higher_is_better"
                    options={directionOptions}
                    value={form.data.higher_is_better ? 'higher' : 'lower'}
                    onChange={(value) => form.setData('higher_is_better', value === 'higher')}
                    error={form.errors.higher_is_better}
                    required
                />
            </FormSection>

            <FormSection
                title="Standards"
                description="Meeting the passing standard scores 60 points; reaching the maximum standard scores 100. Results in between are scaled, and a result short of the passing standard fails the event."
            >
                <div className="grid gap-5 sm:grid-cols-2">
                    <FormField label="Passing standard (60 points)" required error={form.errors.passing_value} hint={valueHint}>
                        <TextInput
                            name="passing_value"
                            value={form.data.passing_value}
                            onChange={(event) => form.setData('passing_value', event.target.value)}
                            inputMode={isTime ? 'text' : 'numeric'}
                            autoComplete="off"
                            className="tabular-nums"
                        />
                    </FormField>
                    <FormField label="Maximum standard (100 points)" required error={form.errors.maximum_value} hint={valueHint}>
                        <TextInput
                            name="maximum_value"
                            value={form.data.maximum_value}
                            onChange={(event) => form.setData('maximum_value', event.target.value)}
                            inputMode={isTime ? 'text' : 'numeric'}
                            autoComplete="off"
                            className="tabular-nums"
                        />
                    </FormField>
                </div>
                {mode === 'edit' && (
                    <CheckboxField
                        label="Event is active"
                        description="Inactive events keep their recorded results but cannot be added to new tests."
                        checked={form.data.is_active}
                        onChange={(event) => form.setData('is_active', event.target.checked)}
                        error={form.errors.is_active}
                    />
                )}
            </FormSection>

            <FormActions>
                <ButtonLink href={routes.fitness.standards.index()} variant="secondary">
                    Cancel
                </ButtonLink>
                <Button type="submit" loading={form.processing}>
                    {submitLabel}
                </Button>
            </FormActions>
        </form>
    );
}
