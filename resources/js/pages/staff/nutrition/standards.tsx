import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/button';
import { FormField, TextInput } from '@/components/ui/form-field';
import { FormSection } from '@/components/ui/form-section';
import { PageHeader } from '@/components/ui/page-header';
import { useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { NutritionStandards } from '@/types/nutrition';

interface StandardsProps {
    standards: NutritionStandards;
    updatedAt: string | null;
    updatedBy: string | null;
}

/** BMI cut-offs, waist risk line and review interval for every campus (institution-wide administrators). */
export default function NutritionStandardsPage({ standards, updatedAt, updatedBy }: StandardsProps) {
    const formatDate = useDateFormatter();
    const form = useForm({
        underweight_below: String(standards.underweightBelow),
        overweight_from: String(standards.overweightFrom),
        obese_from: String(standards.obeseFrom),
        waist_to_height_risk: String(standards.waistToHeightRisk),
        review_interval_days: String(standards.reviewIntervalDays),
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put(routes.nutrition.standards());
    };

    return (
        <>
            <Head title="Nutrition Standards" />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title="Nutrition Standards"
                    description={`Used for every campus.${updatedAt ? ` Last changed ${formatDate.dateTime(updatedAt)}${updatedBy ? ` by ${updatedBy}` : ''}.` : ''}`}
                    breadcrumbs={[{ label: 'Nutrition', href: routes.nutrition.index() }, { label: 'Standards' }]}
                />

                <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                    <FormSection title="Body Mass Index" description="Defaults: the WHO Asia-Pacific cut-offs used by the Department of Health (18.5, 23.0, 27.5).">
                        <div className="grid gap-5 sm:grid-cols-3">
                            <FormField label="Underweight Below" required error={form.errors.underweight_below}>
                                <TextInput inputMode="decimal" value={form.data.underweight_below} onChange={(event) => form.setData('underweight_below', event.target.value)} />
                            </FormField>
                            <FormField label="Overweight From" required error={form.errors.overweight_from}>
                                <TextInput inputMode="decimal" value={form.data.overweight_from} onChange={(event) => form.setData('overweight_from', event.target.value)} />
                            </FormField>
                            <FormField label="Obese From" required error={form.errors.obese_from}>
                                <TextInput inputMode="decimal" value={form.data.obese_from} onChange={(event) => form.setData('obese_from', event.target.value)} />
                            </FormField>
                        </div>
                        <p className="mt-3 text-sm text-ink-muted">Normal is from the underweight line up to the overweight line.</p>
                    </FormSection>

                    <FormSection title="Waist and Reviews">
                        <div className="grid gap-5 sm:grid-cols-2">
                            <FormField label="Waist-to-Height Risk From" required error={form.errors.waist_to_height_risk} hint="Waist ÷ height. 0.50 means the waist is half the height.">
                                <TextInput inputMode="decimal" value={form.data.waist_to_height_risk} onChange={(event) => form.setData('waist_to_height_risk', event.target.value)} />
                            </FormField>
                            <FormField label="Next Review After (days)" required error={form.errors.review_interval_days} hint="Used when the dietitian sets no date.">
                                <TextInput inputMode="numeric" value={form.data.review_interval_days} onChange={(event) => form.setData('review_interval_days', event.target.value)} />
                            </FormField>
                        </div>
                    </FormSection>

                    <div className="flex justify-end gap-3">
                        <ButtonLink href={routes.nutrition.index()} variant="ghost">
                            Cancel
                        </ButtonLink>
                        <Button type="submit" loading={form.processing}>
                            Save Standards
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}
