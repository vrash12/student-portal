import { Head, Link, useForm } from '@inertiajs/react';
import { CircleAlert, CircleCheck, Plus, Trash2 } from 'lucide-react';
import { useRef, type FormEvent } from 'react';
import { PieChart } from '@/components/charts/pie-chart';
import { OfferingDescription } from '@/components/grading/offering-context';
import { ThresholdSummary } from '@/components/grading/standing';
import { componentsText } from '@/components/grading-setup/copy-weights-dialog';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { FormField, SelectInput, TextArea, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { PageHeader, type BreadcrumbItem } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import type { PieSlice } from '@/types/charts';
import type { GradingCategory, GradingThresholds, OfferingContext } from '@/types/grading';
import type { WeightsCopySource } from '@/types/grading-setup';

interface ComponentRow {
    /** Client-only list key; not sent to the server. */
    key: string;
    id: number | null;
    name: string;
    weight: string;
}

interface SubjectWeightsFormData {
    categories: ComponentRow[];
    reason: string;
}

interface SubjectWeightsProps {
    offering: OfferingContext;
    /** The saved components (grading categories) and weights. */
    categories: GradingCategory[];
    /** Other subjects' weights to start from; only offered while this subject has none. */
    copySources: WeightsCopySource[];
    /** "setup" when opened from Grading Setup: saving and cancelling return there. */
    returnTo: 'setup' | null;
    /** Passing and warning grades of the class's academic period; null when not set up. */
    thresholds: GradingThresholds | null;
    periodId: number;
    /** Changing the weights then changes official grades, so a reason is required. */
    hasFinalizedAssessments: boolean;
    totalWeight: number;
    maxCategories: number;
    can: { viewClass: boolean };
}

/**
 * The components (for example Quizzes, Examinations) and weights of one
 * subject in one class. The weights add up to 100%; the server checks it
 * and every other rule when saving.
 */
export default function SubjectWeights({
    offering,
    categories,
    copySources,
    returnTo,
    thresholds,
    periodId,
    hasFinalizedAssessments,
    totalWeight,
    maxCategories,
    can,
}: SubjectWeightsProps) {
    const nextKey = useRef(0);
    const newRow = (name = '', weight = ''): ComponentRow => {
        nextKey.current += 1;

        return { key: `new-${nextKey.current}`, id: null, name, weight };
    };

    const rowsFrom = (saved: GradingCategory[]): ComponentRow[] =>
        saved.length > 0
            ? saved.map((category) => ({ key: `category-${category.id}`, id: category.id, name: category.name, weight: category.weight }))
            : [newRow()];

    const form = useForm<SubjectWeightsFormData>({
        categories: rowsFrom(categories),
        reason: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const assessmentCounts = new Map(categories.map((category) => [category.id, category.assessmentCount]));
    const subjectInClass = `${offering.subject.name} in ${offering.classBatch.name}`;

    const fromSetup = returnTo === 'setup';
    const setupHref = routes.gradingSetup.index({ period: String(periodId) });
    const classHref = routes.classes.show(offering.classBatch.id);
    const cancelHref = fromSetup ? setupHref : can.viewClass ? classHref : routes.dashboard();
    const breadcrumbs: BreadcrumbItem[] = fromSetup
        ? [{ label: 'Grading Setup', href: setupHref }, { label: `Weights: ${offering.subject.name} · ${offering.classBatch.name}` }]
        : can.viewClass
          ? [
                { label: terms.classBatch.plural, href: routes.classes.index() },
                { label: offering.classBatch.name, href: classHref },
                { label: `Weights: ${offering.subject.name}` },
            ]
          : [{ label: `Weights: ${offering.subject.name}` }];

    // Preview only: the server checks the total when saving.
    const total = form.data.categories.reduce((sum, category) => {
        const weight = Number(category.weight);

        return sum + (Number.isFinite(weight) ? Math.round(weight * 100) : 0);
    }, 0);
    const totalIsValid = total === totalWeight * 100;
    // The ring always shows the whole subject grade: the part no component
    // has yet is its own neutral slice, so 40% looks like 40%, not like all.
    const unassigned = totalWeight * 100 - total;
    const slices: PieSlice[] = [
        ...form.data.categories
            .map((category, index) => ({ label: category.name.trim() || `Component ${index + 1}`, value: Number(category.weight) }))
            .filter((slice) => Number.isFinite(slice.value) && slice.value > 0),
        ...(unassigned > 0 ? [{ label: 'Unassigned', value: unassigned / 100, tone: 'none' as const }] : []),
    ];

    const updateRow = (index: number, patch: Partial<ComponentRow>) => {
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

    // Fills the form only; nothing is saved until Save Weights.
    const startFrom = (classSubjectId: string) => {
        const source = copySources.find((option) => String(option.classSubjectId) === classSubjectId);
        if (source === undefined) {
            return;
        }
        form.clearErrors();
        form.setData(
            'categories',
            source.components.map((component) => newRow(component.name, component.weight)),
        );
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            categories: data.categories.map(({ id, name, weight }) => ({ id, name, weight })),
            return: returnTo ?? '',
        }));
        form.put(routes.classes.grading(offering.classBatch.id, offering.id), {
            preserveScroll: true,
            // When the save returns to this page, new rows must take the ids
            // the server gave them, or the next save would recreate them.
            onSuccess: (page) => {
                if (page.component !== 'staff/classes/grading') {
                    return;
                }
                const saved = (page.props as unknown as SubjectWeightsProps).categories;
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
            <Head title={`Weights · ${offering.subject.name}`} />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title="Subject Weights"
                    description={
                        <>
                            {offering.subject.name} · <OfferingDescription offering={offering} />
                        </>
                    }
                    breadcrumbs={breadcrumbs}
                />

                {/* Outside the form, so following the links is not mistaken for part of saving. */}
                <div className="mb-6 flex flex-col gap-2 text-sm text-ink-muted">
                    <p>
                        These weights apply only to {subjectInClass}.{' '}
                        <Link href={setupHref} className="font-medium text-primary-700 underline">
                            Open Grading Setup
                        </Link>
                    </p>
                    <p>
                        {thresholds === null ? (
                            <>No standing yet: Passing and Warning Grades are not set for {offering.period.name}. </>
                        ) : (
                            <>
                                Standing uses <ThresholdSummary thresholds={thresholds} /> ({offering.period.name}).{' '}
                            </>
                        )}
                        <Link href={routes.academicPeriods.thresholds(periodId)} className="font-medium text-primary-700 underline">
                            {thresholds === null ? 'Set Passing and Warning Grades' : 'Change Passing and Warning Grades'}
                        </Link>
                    </p>
                    {form.isDirty && <p className="font-medium text-warning-fg">Unsaved changes: leaving discards them.</p>}
                </div>

                <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                    {hasFinalizedAssessments && (
                        <Alert tone="warning" title="These weights are in use">
                            Changes recalculate grades and standings and need a reason.
                        </Alert>
                    )}

                    {categories.length === 0 && copySources.length > 0 && (
                        <FormSection title="Start From Another Subject">
                            <FormField label="Copy Weights From">
                                <SelectInput defaultValue="" onChange={(event) => startFrom(event.target.value)}>
                                    <option value="" disabled>
                                        Choose a subject…
                                    </option>
                                    {copySources.map((option) => (
                                        <option key={option.classSubjectId} value={String(option.classSubjectId)}>
                                            {option.label}: {componentsText(option.components)}
                                        </option>
                                    ))}
                                </SelectInput>
                            </FormField>
                        </FormSection>
                    )}

                    <FormSection
                        title="Components and Weights"
                        description={`Weights must total ${totalWeight}%.`}
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
                                                    Component<span className="sr-only"> {position}</span>
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
                                                placeholder="e.g. Quizzes"
                                            />
                                        </FormField>
                                        <FormField
                                            label={
                                                <>
                                                    Weight (%)<span className="sr-only"> for component {position}</span>
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
                                                aria-label={`Remove component ${category.name || position}`}
                                                title={assessmentCount > 0 ? 'Has assessments: cannot be removed.' : undefined}
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
                                Add Component
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

                        {/* One slice alone says nothing a ring can show better than the total. */}
                        {(slices.length >= 2 || unassigned < 0) && (
                            <section aria-labelledby="weights-preview-heading" className="flex flex-col gap-2 border-t border-line pt-4">
                                <h3 id="weights-preview-heading" className="text-sm font-semibold text-ink">
                                    Share of Subject Grade
                                </h3>
                                {unassigned < 0 ? (
                                    <p className="text-sm text-ink-muted">Weights exceed {totalWeight}%.</p>
                                ) : (
                                    <PieChart
                                        slices={slices}
                                        noun={{ one: 'of the grade', other: 'of the grade' }}
                                        formatValue={(value) => `${formatWeight(Math.round(value * 100))}%`}
                                        showShare={false}
                                    />
                                )}
                            </section>
                        )}

                        <p className="text-sm text-ink-muted">Components with assessments cannot be removed.</p>
                    </FormSection>

                    {hasFinalizedAssessments && (
                        <FormSection title="Reason for Change">
                            <FormField label="Reason" required error={errors.reason} hint="Kept in the audit log.">
                                <TextArea value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} maxLength={500} rows={3} />
                            </FormField>
                        </FormSection>
                    )}

                    <FormActions>
                        <ButtonLink href={cancelHref} variant="secondary">
                            Cancel
                        </ButtonLink>
                        <Button type="submit" loading={form.processing}>
                            Save Weights
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
