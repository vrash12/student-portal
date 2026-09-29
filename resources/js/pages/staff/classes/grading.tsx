import { Head, Link, useForm } from '@inertiajs/react';
import { CircleAlert, CircleCheck, Plus, Trash2 } from 'lucide-react';
import { useRef, type FormEvent } from 'react';
import { OfferingDescription } from '@/components/grading/offering-context';
import { ThresholdSummary } from '@/components/grading/standing';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { FormField, TextArea, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { PageHeader, type BreadcrumbItem } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import type { GradingCategory, GradingThresholds, OfferingContext } from '@/types/grading';

interface CategoryRow {
    /** Client-only list key; not sent to the server. */
    key: string;
    id: number | null;
    name: string;
    weight: string;
}

interface GradingSetupFormData {
    categories: CategoryRow[];
    reason: string;
}

interface GradingSetupProps {
    offering: OfferingContext;
    categories: GradingCategory[];
    /** Passing and warning grades of the class's academic period; null when not set up. */
    thresholds: GradingThresholds | null;
    periodId: number;
    /** Changing the setup then changes official grades, so a reason is required. */
    hasFinalizedAssessments: boolean;
    totalWeight: number;
    maxCategories: number;
    can: { viewClass: boolean };
}

export default function GradingSetup({
    offering,
    categories,
    thresholds,
    periodId,
    hasFinalizedAssessments,
    totalWeight,
    maxCategories,
    can,
}: GradingSetupProps) {
    const nextKey = useRef(0);
    const newRow = (): CategoryRow => {
        nextKey.current += 1;

        return { key: `new-${nextKey.current}`, id: null, name: '', weight: '' };
    };

    const rowsFrom = (saved: GradingCategory[]): CategoryRow[] =>
        saved.length > 0
            ? saved.map((category) => ({ key: `category-${category.id}`, id: category.id, name: category.name, weight: category.weight }))
            : [newRow()];

    const form = useForm<GradingSetupFormData>({
        categories: rowsFrom(categories),
        reason: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const assessmentCounts = new Map(categories.map((category) => [category.id, category.assessmentCount]));

    const classHref = routes.classes.show(offering.classBatch.id);
    const cancelHref = can.viewClass ? classHref : routes.dashboard();
    const breadcrumbs: BreadcrumbItem[] = can.viewClass
        ? [
              { label: terms.classBatch.plural, href: routes.classes.index() },
              { label: offering.classBatch.name, href: classHref },
              { label: `Grading: ${offering.subject.name}` },
          ]
        : [{ label: `Grading: ${offering.subject.name}` }];

    // Preview only: the server checks the total when saving.
    const total = form.data.categories.reduce((sum, category) => {
        const weight = Number(category.weight);

        return sum + (Number.isFinite(weight) ? Math.round(weight * 100) : 0);
    }, 0);
    const totalIsValid = total === totalWeight * 100;

    const updateRow = (index: number, patch: Partial<CategoryRow>) => {
        form.setData(
            'categories',
            form.data.categories.map((category, rowIndex) => (rowIndex === index ? { ...category, ...patch } : category)),
        );
    };

    // Server errors are keyed by row position, so they no longer apply once
    // rows are added or removed.
    const addRow = () => {
        form.clearErrors();
        form.setData('categories', [...form.data.categories, newRow()]);
    };

    const removeRow = (index: number) => {
        form.clearErrors();
        form.setData(
            'categories',
            form.data.categories.filter((_, rowIndex) => rowIndex !== index),
        );
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            categories: data.categories.map(({ id, name, weight }) => ({ id, name, weight })),
        }));
        form.put(routes.classes.grading(offering.classBatch.id, offering.id), {
            preserveScroll: true,
            // When the save returns to this page, new rows must take the ids
            // the server gave them, or the next save would recreate them.
            onSuccess: (page) => {
                if (page.component !== 'staff/classes/grading') {
                    return;
                }
                const saved = (page.props as unknown as GradingSetupProps).categories;
                const next = { categories: rowsFrom(saved), reason: '' };
                // Set both explicitly: reset() from this closure would restore
                // the defaults of the render that submitted, not these.
                form.setDefaults(next);
                form.setData(next);
            },
        });
    };

    return (
        <>
            <Head title={`Grading Setup · ${offering.subject.name}`} />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title="Grading Setup"
                    description={
                        <>
                            {offering.subject.name} · <OfferingDescription offering={offering} />
                        </>
                    }
                    breadcrumbs={breadcrumbs}
                />

                {/* Outside the form, so following the link is not mistaken for part of saving. */}
                <p className="mb-6 text-sm text-ink-muted">
                    {thresholds === null ? (
                        <>Academic standing is not shown yet: passing and warning grades have not been set for {offering.period.name}. </>
                    ) : (
                        <>
                            Academic standing in this subject uses the <ThresholdSummary thresholds={thresholds} /> of {offering.period.name}.{' '}
                        </>
                    )}
                    <Link href={routes.academicPeriods.thresholds(periodId)} className="font-medium text-primary-700 underline">
                        {thresholds === null ? 'Set passing and warning grades' : 'Change passing and warning grades'}
                    </Link>
                    {form.isDirty && '. Save your changes first: leaving this page discards them.'}
                </p>

                <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                    {hasFinalizedAssessments && (
                        <Alert tone="warning" title="This subject has finalized assessments">
                            Changing categories or weights recalculates grades that already count. A reason is required and is kept in the audit
                            log.
                        </Alert>
                    )}

                    <FormSection
                        title="Grading Categories"
                        description={`Each assessment belongs to one category. The weights must add up to exactly ${totalWeight}%.`}
                    >
                        {errors.categories && (
                            <p className="flex items-start gap-1.5 text-sm text-danger-fg" role="alert">
                                <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                <span>{errors.categories}</span>
                            </p>
                        )}

                        <ol className="flex flex-col gap-4">
                            {form.data.categories.map((category, index) => {
                                const assessmentCount = category.id === null ? 0 : (assessmentCounts.get(category.id) ?? 0);
                                const position = index + 1;

                                return (
                                    <li key={category.key} className="grid gap-3 sm:grid-cols-[1fr_8rem_auto] sm:items-start">
                                        <FormField
                                            label={
                                                <>
                                                    Category Name<span className="sr-only"> {position}</span>
                                                </>
                                            }
                                            required
                                            error={errors[`categories.${index}.name`]}
                                            hint={assessmentCount > 0 ? `${assessmentCount} ${assessmentCount === 1 ? 'assessment' : 'assessments'}` : undefined}
                                        >
                                            <TextInput
                                                value={category.name}
                                                onChange={(event) => updateRow(index, { name: event.target.value })}
                                                maxLength={100}
                                                autoComplete="off"
                                                placeholder="For example Quizzes"
                                            />
                                        </FormField>
                                        <FormField
                                            label={
                                                <>
                                                    Weight (%)<span className="sr-only"> for category {position}</span>
                                                </>
                                            }
                                            required
                                            error={errors[`categories.${index}.weight`]}
                                        >
                                            <TextInput
                                                value={category.weight}
                                                onChange={(event) => updateRow(index, { weight: event.target.value })}
                                                inputMode="decimal"
                                                autoComplete="off"
                                                className="tabular-nums"
                                            />
                                        </FormField>
                                        <div className="sm:pt-7">
                                            <Button
                                                variant="ghost"
                                                icon={<Trash2 className="size-4" aria-hidden="true" />}
                                                onClick={() => removeRow(index)}
                                                disabled={assessmentCount > 0 || form.data.categories.length === 1}
                                                aria-label={`Remove category ${category.name || position}`}
                                                title={assessmentCount > 0 ? 'Categories with assessments cannot be removed.' : undefined}
                                            >
                                                <span className="sm:sr-only">Remove</span>
                                            </Button>
                                        </div>
                                    </li>
                                );
                            })}
                        </ol>

                        <div className="flex flex-col gap-3 border-t border-line pt-4 sm:flex-row sm:items-center sm:justify-between">
                            <Button
                                variant="secondary"
                                icon={<Plus className="size-4" aria-hidden="true" />}
                                onClick={addRow}
                                disabled={form.data.categories.length >= maxCategories}
                            >
                                Add Category
                            </Button>
                            <p className="flex items-center gap-1.5 text-sm" role="status">
                                {totalIsValid ? (
                                    <>
                                        <CircleCheck className="size-4 text-success-fg" aria-hidden="true" />
                                        <span className="text-ink">
                                            Total: <strong className="tabular-nums">{totalWeight}%</strong>
                                        </span>
                                    </>
                                ) : (
                                    <>
                                        <CircleAlert className="size-4 text-warning-fg" aria-hidden="true" />
                                        <span className="text-ink">
                                            Total: <strong className="tabular-nums">{formatWeight(total)}%</strong> of {totalWeight}%
                                        </span>
                                    </>
                                )}
                            </p>
                        </div>
                        <p className="text-sm text-ink-muted">Categories that already have assessments can be renamed or reweighted, but not removed.</p>
                    </FormSection>

                    {hasFinalizedAssessments && (
                        <FormSection title="Reason for Change">
                            <FormField label="Reason" required error={errors.reason} hint="Kept in the audit log with the previous and new setup.">
                                <TextArea value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} maxLength={500} rows={3} />
                            </FormField>
                        </FormSection>
                    )}

                    <FormActions>
                        <ButtonLink href={cancelHref} variant="secondary">
                            Cancel
                        </ButtonLink>
                        <Button type="submit" loading={form.processing}>
                            Save Grading Setup
                        </Button>
                    </FormActions>
                </form>
            </div>
        </>
    );
}

/** Hundredths of a percent as a compact number, e.g. 9550 -> "95.5". */
function formatWeight(hundredths: number): string {
    return String(hundredths / 100);
}
