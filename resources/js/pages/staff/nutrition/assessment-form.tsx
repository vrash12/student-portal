import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { FormField, SelectInput, TextArea, TextInput } from '@/components/ui/form-field';
import { FormSection } from '@/components/ui/form-section';
import { PageHeader } from '@/components/ui/page-header';
import { formatCalendarDate } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { NutritionAssessment, NutritionCandidateSummary, NutritionStandards, Option } from '@/types/nutrition';

interface AssessmentFormProps {
    candidate: NutritionCandidateSummary;
    /** Null when recording a new assessment. */
    assessment: NutritionAssessment | null;
    /** New assessments: today, and the height measured last time. */
    defaults: { heightCm: number | null; assessedOn: string } | null;
    activityLevels: Option[];
    goals: Option[];
    standards: NutritionStandards;
}

const text = (value: number | string | null | undefined): string => (value === null || value === undefined ? '' : String(value));

/**
 * A nutrition assessment, in the order of the Nutrition Care Process:
 * measurements, food and nutrition history, findings, diagnosis and plan.
 */
export default function AssessmentForm({ candidate, assessment, defaults, activityLevels, goals, standards }: AssessmentFormProps) {
    const editing = assessment !== null;
    const form = useForm({
        assessed_on: assessment?.assessedOn ?? defaults?.assessedOn ?? '',
        height_cm: text(assessment?.heightCm ?? defaults?.heightCm),
        weight_kg: text(assessment?.weightKg),
        waist_cm: text(assessment?.waistCm),
        body_fat_percent: text(assessment?.bodyFatPercent),
        activity_level: assessment?.activityLevel?.value ?? '',
        meals_per_day: text(assessment?.mealsPerDay),
        diet_history: assessment?.dietHistory ?? '',
        clinical_findings: assessment?.clinicalFindings ?? '',
        lab_findings: assessment?.labFindings ?? '',
        diagnosis: assessment?.diagnosis ?? '',
        goal: assessment?.goal?.value ?? '',
        target_weight_kg: text(assessment?.targetWeightKg),
        energy_target_kcal: text(assessment?.energyTargetKcal),
        plan: assessment?.plan ?? '',
        next_review_on: assessment?.nextReviewOn ?? '',
    });

    const preview = bmiPreview(form.data.height_cm, form.data.weight_kg, form.data.waist_cm, standards);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (editing) {
            form.put(routes.nutrition.assessments.update(assessment.id));
        } else {
            form.post(routes.nutrition.assessments.store(candidate.id));
        }
    };

    const title = editing ? 'Correct Assessment' : 'New Assessment';

    return (
        <>
            <Head title={`${title} · ${candidate.name}`} />

            <div className="mx-auto max-w-4xl">
                <PageHeader
                    title={title}
                    description={`${candidate.name} · Candidate ${candidate.number}${candidate.className ? ` · ${candidate.className}` : ''}${editing ? ` · Assessed ${formatCalendarDate(assessment.assessedOn)}` : ''}`}
                    breadcrumbs={[{ label: 'Nutrition', href: routes.nutrition.index() }, { label: candidate.name, href: routes.nutrition.show(candidate.id) }, { label: title }]}
                />

                <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                    <FormSection title="Measurements" step={1}>
                        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            <FormField label="Date Assessed" required error={form.errors.assessed_on}>
                                <TextInput type="date" value={form.data.assessed_on} onChange={(event) => form.setData('assessed_on', event.target.value)} />
                            </FormField>
                            <FormField label="Height (cm)" required error={form.errors.height_cm}>
                                <TextInput inputMode="decimal" value={form.data.height_cm} onChange={(event) => form.setData('height_cm', event.target.value)} placeholder="170.0" />
                            </FormField>
                            <FormField label="Weight (kg)" required error={form.errors.weight_kg}>
                                <TextInput inputMode="decimal" value={form.data.weight_kg} onChange={(event) => form.setData('weight_kg', event.target.value)} placeholder="65.0" />
                            </FormField>
                            <FormField label="Waist (cm)" error={form.errors.waist_cm} hint="Midway between the lowest rib and the hip bone.">
                                <TextInput inputMode="decimal" value={form.data.waist_cm} onChange={(event) => form.setData('waist_cm', event.target.value)} />
                            </FormField>
                            <FormField label="Body Fat (%)" error={form.errors.body_fat_percent} hint="Optional, when measured.">
                                <TextInput inputMode="decimal" value={form.data.body_fat_percent} onChange={(event) => form.setData('body_fat_percent', event.target.value)} />
                            </FormField>
                        </div>
                        {preview !== null && (
                            <p className="mt-4 rounded-md bg-surface-muted px-4 py-3 text-sm text-ink" role="status">
                                Preview: BMI <strong className="font-semibold tabular-nums">{preview.bmi.toFixed(1)}</strong> ({preview.category})
                                {preview.waistToHeight !== null && (
                                    <>
                                        {' '}
                                        · waist ÷ height <strong className="font-semibold tabular-nums">{preview.waistToHeight.toFixed(2)}</strong>
                                        {preview.waistAtRisk ? ' (at risk)' : ''}
                                    </>
                                )}
                                . The system calculates the recorded values when saved.
                            </p>
                        )}
                    </FormSection>

                    <FormSection title="Food and Nutrition History" step={2}>
                        <div className="grid gap-5 sm:grid-cols-2">
                            <FormField label="Activity Level" error={form.errors.activity_level}>
                                <SelectInput value={form.data.activity_level} onChange={(event) => form.setData('activity_level', event.target.value)}>
                                    <option value="">Not recorded</option>
                                    {activityLevels.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </SelectInput>
                            </FormField>
                            <FormField label="Meals a Day" error={form.errors.meals_per_day}>
                                <TextInput inputMode="numeric" value={form.data.meals_per_day} onChange={(event) => form.setData('meals_per_day', event.target.value)} />
                            </FormField>
                        </div>
                        <FormField label="Diet History" error={form.errors.diet_history} hint="What the candidate ate in the last 24 hours, usual meals, snacks and drinks." className="mt-5">
                            <TextArea rows={4} maxLength={5000} value={form.data.diet_history} onChange={(event) => form.setData('diet_history', event.target.value)} />
                        </FormField>
                    </FormSection>

                    <FormSection title="Findings" step={3} description="Staff only. Lab results are in the medical record (view only).">
                        <div className="grid gap-5 sm:grid-cols-2">
                            <FormField label="Clinical Findings" error={form.errors.clinical_findings} hint="Signs seen or reported: fatigue, cramps, appetite, weight loss.">
                                <TextArea rows={4} maxLength={5000} value={form.data.clinical_findings} onChange={(event) => form.setData('clinical_findings', event.target.value)} />
                            </FormField>
                            <FormField label="Lab Findings" error={form.errors.lab_findings} hint="Relevant results, for example hemoglobin or blood sugar.">
                                <TextArea rows={4} maxLength={5000} value={form.data.lab_findings} onChange={(event) => form.setData('lab_findings', event.target.value)} />
                            </FormField>
                        </div>
                    </FormSection>

                    <FormSection title="Diagnosis and Plan" step={4} description="The candidate sees the goal, targets, plan and next review, not the diagnosis.">
                        <FormField label="Nutrition Diagnosis" error={form.errors.diagnosis} hint="Problem, cause and signs, for example: inadequate energy intake related to skipped meals, shown by 3 kg weight loss.">
                            <TextArea rows={3} maxLength={2000} value={form.data.diagnosis} onChange={(event) => form.setData('diagnosis', event.target.value)} />
                        </FormField>
                        <div className="mt-5 grid gap-5 sm:grid-cols-3">
                            <FormField label="Goal" error={form.errors.goal}>
                                <SelectInput value={form.data.goal} onChange={(event) => form.setData('goal', event.target.value)}>
                                    <option value="">No goal set</option>
                                    {goals.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </SelectInput>
                            </FormField>
                            <FormField label="Target Weight (kg)" error={form.errors.target_weight_kg}>
                                <TextInput inputMode="decimal" value={form.data.target_weight_kg} onChange={(event) => form.setData('target_weight_kg', event.target.value)} />
                            </FormField>
                            <FormField label="Daily Energy (kcal)" error={form.errors.energy_target_kcal}>
                                <TextInput inputMode="numeric" value={form.data.energy_target_kcal} onChange={(event) => form.setData('energy_target_kcal', event.target.value)} />
                            </FormField>
                        </div>
                        <FormField label="Plan" error={form.errors.plan} hint="What the candidate should do: meals, portions, snacks, water, supplements." className="mt-5">
                            <TextArea rows={4} maxLength={5000} value={form.data.plan} onChange={(event) => form.setData('plan', event.target.value)} />
                        </FormField>
                        <FormField
                            label="Next Review"
                            error={form.errors.next_review_on}
                            hint={`Leave empty for ${standards.reviewIntervalDays} days after the assessment.`}
                            className="mt-5 sm:w-64"
                        >
                            <TextInput type="date" value={form.data.next_review_on} onChange={(event) => form.setData('next_review_on', event.target.value)} />
                        </FormField>
                    </FormSection>

                    {Object.keys(form.errors).length > 0 && <Alert tone="danger">Some fields need attention. Check the messages above.</Alert>}

                    <div className="flex justify-end gap-3">
                        <ButtonLink href={routes.nutrition.show(candidate.id)} variant="ghost">
                            Cancel
                        </ButtonLink>
                        <Button type="submit" loading={form.processing}>
                            {editing ? 'Save Correction' : 'Record Assessment'}
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

/** A preview only: the server calculates and classifies what is recorded. */
function bmiPreview(height: string, weight: string, waist: string, standards: NutritionStandards) {
    const heightCm = Number(height);
    const weightKg = Number(weight);
    if (!(heightCm >= 100 && heightCm <= 250 && weightKg >= 25 && weightKg <= 300)) {
        return null;
    }
    const bmi = Math.round((weightKg / (heightCm / 100) ** 2) * 10) / 10;
    const category = bmi < standards.underweightBelow ? 'Underweight' : bmi < standards.overweightFrom ? 'Normal' : bmi < standards.obeseFrom ? 'Overweight' : 'Obese';
    const waistCm = Number(waist);
    const waistToHeight = waist !== '' && waistCm >= 40 ? Math.round((waistCm / heightCm) * 100) / 100 : null;

    return { bmi, category, waistToHeight, waistAtRisk: waistToHeight !== null && waistToHeight >= standards.waistToHeightRisk };
}
