import type { InertiaForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/button';
import { FormField, SelectInput, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import type { GradingCategory } from '@/types/grading';

export interface AssessmentFormData {
    title: string;
    assessment_category_id: string;
    max_score: string;
    assessed_on: string;
}

interface AssessmentFormProps {
    form: InertiaForm<AssessmentFormData>;
    categories: GradingCategory[];
    submitLabel: string;
    cancelHref: string;
    /** Highest score already recorded; the maximum score cannot go below it. */
    highestScore?: string | null;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}

export function AssessmentForm({ form, categories, submitLabel, cancelHref, highestScore = null, onSubmit }: AssessmentFormProps) {
    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-6">
            <FormSection title="Assessment Details" description="Scores are recorded after the assessment is created.">
                <FormField label="Title" required error={form.errors.title} hint="For example Quiz 1 or Midterm Examination.">
                    <TextInput
                        name="title"
                        value={form.data.title}
                        onChange={(event) => form.setData('title', event.target.value)}
                        maxLength={150}
                        autoComplete="off"
                    />
                </FormField>

                <div className="grid gap-5 sm:grid-cols-2">
                    <FormField
                        label="Grading Category"
                        required
                        error={form.errors.assessment_category_id}
                        hint="Decides which weight the scores count toward."
                    >
                        <SelectInput
                            name="assessment_category_id"
                            value={form.data.assessment_category_id}
                            onChange={(event) => form.setData('assessment_category_id', event.target.value)}
                        >
                            <option value="">Select a category</option>
                            {categories.map((category) => (
                                <option key={category.id} value={String(category.id)}>
                                    {category.name} ({category.weight}%)
                                </option>
                            ))}
                        </SelectInput>
                    </FormField>

                    <FormField
                        label="Maximum Score"
                        required
                        error={form.errors.max_score}
                        hint={highestScore === null ? 'Highest possible raw score.' : `Highest possible raw score. At least ${highestScore}, the highest score recorded.`}
                    >
                        <TextInput
                            name="max_score"
                            value={form.data.max_score}
                            onChange={(event) => form.setData('max_score', event.target.value)}
                            inputMode="decimal"
                            autoComplete="off"
                            className="tabular-nums"
                        />
                    </FormField>
                </div>

                <FormField label="Date" error={form.errors.assessed_on} hint="Optional. Dated assessments appear under Upcoming Assessments." className="sm:w-1/2 sm:pr-2.5">
                    <TextInput
                        type="date"
                        name="assessed_on"
                        value={form.data.assessed_on}
                        onChange={(event) => form.setData('assessed_on', event.target.value)}
                    />
                </FormField>
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
