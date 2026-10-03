import type { InertiaForm } from '@inertiajs/react';
import { BookOpen, CalendarCheck, Dumbbell, Medal, type LucideIcon } from 'lucide-react';
import type { FormEvent } from 'react';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { CheckboxField, FormField, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { RadioCards } from '@/components/ui/radio-cards';
import { routes } from '@/lib/routes';
import type { ActiveSingleSourceAreas, AreaSubjectOption, PerformanceSourceOption, PerformanceSourceValue } from '@/types/performance';

export interface PerformanceAreaFormData {
    name: string;
    description: string;
    source: PerformanceSourceValue;
    weight: string;
    passing_grade: string;
    must_pass: boolean;
    base_rating: string;
    merit_value: string;
    demerit_value: string;
    sort_order: string;
    is_active: boolean;
    subject_ids: number[];
}

const SOURCE_ICONS: Record<PerformanceSourceValue, LucideIcon> = {
    subjects: BookOpen,
    fitness: Dumbbell,
    conduct: Medal,
    attendance: CalendarCheck,
};

/** Sources with a single result per candidate: at most one active area each. */
const SINGLE_SOURCES: PerformanceSourceValue[] = ['fitness', 'conduct', 'attendance'];

/**
 * Sends only the fields of the chosen source: subjects for subject areas,
 * the rating values for conduct areas (the server refuses the others).
 */
export function areaPayload(data: PerformanceAreaFormData): Record<string, string | boolean | number[]> {
    const { base_rating, merit_value, demerit_value, subject_ids, ...common } = data;

    return {
        ...common,
        ...(data.source === 'conduct' ? { base_rating, merit_value, demerit_value } : {}),
        subject_ids: data.source === 'subjects' ? subject_ids : [],
    };
}

interface PerformanceAreaFormProps {
    form: InertiaForm<PerformanceAreaFormData>;
    /** The area being edited; subjects already mapped to it are not "moved". */
    areaId: number | null;
    sources: PerformanceSourceOption[];
    subjects: AreaSubjectOption[];
    activeSingleSourceAreas: ActiveSingleSourceAreas;
    submitLabel: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}

/** Name, source, weight, passing grade and must-pass rule of an area, with the fields of its source. */
export function PerformanceAreaForm({ form, areaId, sources, subjects, activeSingleSourceAreas, submitLabel, onSubmit }: PerformanceAreaFormProps) {
    const source = form.data.source;
    const conflictingArea = SINGLE_SOURCES.includes(source) && form.data.is_active ? (activeSingleSourceAreas[source] ?? null) : null;
    const subjectErrors = Object.entries(form.errors)
        .filter(([key]) => key === 'subject_ids' || key.startsWith('subject_ids.'))
        .map(([, message]) => message);

    const toggleSubject = (subjectId: number, checked: boolean) => {
        form.setData('subject_ids', checked ? [...form.data.subject_ids, subjectId] : form.data.subject_ids.filter((id) => id !== subjectId));
    };

    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-6">
            <FormSection title="Area">
                <FormField label="Name" required error={form.errors.name}>
                    <TextInput
                        name="name"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        maxLength={100}
                        autoComplete="off"
                        placeholder="e.g. Academic"
                    />
                </FormField>

                <FormField label="Description" error={form.errors.description} hint="Optional.">
                    <TextInput
                        name="description"
                        value={form.data.description}
                        onChange={(event) => form.setData('description', event.target.value)}
                        maxLength={255}
                        autoComplete="off"
                    />
                </FormField>

                <RadioCards
                    legend="Grade taken from"
                    name="source"
                    options={sources.map((option) => ({ ...option, icon: SOURCE_ICONS[option.value] }))}
                    value={source}
                    onChange={(value) => {
                        const chosen = sources.find((option) => option.value === value);
                        if (chosen !== undefined) {
                            form.setData('source', chosen.value);
                        }
                    }}
                    error={form.errors.source}
                    required
                />

                {conflictingArea !== null && (
                    <Alert tone="warning" title={`${conflictingArea} already uses this source`}>
                        Only one can be active. Save this area as inactive, or deactivate {conflictingArea} first.
                    </Alert>
                )}
            </FormSection>

            <FormSection title="Grading">
                <div className="grid gap-5 sm:grid-cols-2">
                    <FormField
                        label="Weight in the final course grade"
                        required
                        error={form.errors.weight}
                        hint="0–100, relative to other areas. 0 leaves it out."
                    >
                        <TextInput
                            name="weight"
                            value={form.data.weight}
                            onChange={(event) => form.setData('weight', event.target.value)}
                            inputMode="decimal"
                            autoComplete="off"
                            className="tabular-nums"
                        />
                    </FormField>
                    <FormField label="Passing grade" required error={form.errors.passing_grade} hint="Above 0, up to 100. Grades at or above it pass.">
                        <TextInput
                            name="passing_grade"
                            value={form.data.passing_grade}
                            onChange={(event) => form.setData('passing_grade', event.target.value)}
                            inputMode="decimal"
                            autoComplete="off"
                            className="tabular-nums"
                        />
                    </FormField>
                </div>

                <CheckboxField
                    label="Must pass to qualify"
                    description="Failing it makes the candidate Not Qualified."
                    checked={form.data.must_pass}
                    onChange={(event) => form.setData('must_pass', event.target.checked)}
                    error={form.errors.must_pass}
                />

                <div className="grid gap-5 sm:grid-cols-2">
                    <FormField label="Order" required error={form.errors.sort_order} hint="Position in lists, 0–999.">
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

                <CheckboxField
                    label="Area is active"
                    description={
                        areaId === null
                            ? 'Inactive areas are not used. Turn off to prepare one in advance.'
                            : 'Inactive areas are kept but not used.'
                    }
                    checked={form.data.is_active}
                    onChange={(event) => form.setData('is_active', event.target.checked)}
                    error={form.errors.is_active}
                />
            </FormSection>

            {source === 'subjects' && (
                <FormSection title="Subjects" description="Each subject counts toward one area only.">
                    {subjects.length === 0 ? (
                        <p className="text-sm text-ink-muted">No subjects yet. Add subjects first.</p>
                    ) : (
                        <fieldset>
                            <legend className="sr-only">Subjects of this area</legend>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {subjects.map((subject) => {
                                    const elsewhere = subject.area !== null && subject.area.id !== areaId ? subject.area.name : null;
                                    const details = [
                                        subject.code,
                                        subject.isActive ? null : 'Inactive',
                                        elsewhere === null ? null : `In ${elsewhere}; saving moves it here`,
                                    ].filter((part): part is string => part !== null);

                                    return (
                                        <CheckboxField
                                            key={subject.id}
                                            label={subject.name}
                                            description={details.join(' · ')}
                                            checked={form.data.subject_ids.includes(subject.id)}
                                            onChange={(event) => toggleSubject(subject.id, event.target.checked)}
                                            className="rounded-lg border border-line-box px-4 py-3"
                                        />
                                    );
                                })}
                            </div>
                        </fieldset>
                    )}
                    {subjectErrors.length > 0 && (
                        <p role="alert" className="text-sm text-danger-fg">
                            {subjectErrors[0]}
                        </p>
                    )}
                </FormSection>
            )}

            {source === 'conduct' && (
                <FormSection
                    title="Conduct Rating"
                    description="Rating = base + merit points × merit value − demerit points × demerit value, within 0–100. Voided entries don't count."
                >
                    <div className="grid gap-5 sm:grid-cols-3">
                        <FormField label="Base rating" required error={form.errors.base_rating} hint="0–100. Rating with no entries.">
                            <TextInput
                                name="base_rating"
                                value={form.data.base_rating}
                                onChange={(event) => form.setData('base_rating', event.target.value)}
                                inputMode="decimal"
                                autoComplete="off"
                                className="tabular-nums"
                            />
                        </FormField>
                        <FormField label="Value of a merit point" required error={form.errors.merit_value} hint="0–100. 0 ignores merits.">
                            <TextInput
                                name="merit_value"
                                value={form.data.merit_value}
                                onChange={(event) => form.setData('merit_value', event.target.value)}
                                inputMode="decimal"
                                autoComplete="off"
                                className="tabular-nums"
                            />
                        </FormField>
                        <FormField label="Value of a demerit point" required error={form.errors.demerit_value} hint="0–100.">
                            <TextInput
                                name="demerit_value"
                                value={form.data.demerit_value}
                                onChange={(event) => form.setData('demerit_value', event.target.value)}
                                inputMode="decimal"
                                autoComplete="off"
                                className="tabular-nums"
                            />
                        </FormField>
                    </div>
                </FormSection>
            )}

            {source === 'fitness' && (
                <Alert title="Fitness grade">Points in the class's latest fitness test with results. Any failed event fails the area.</Alert>
            )}

            {source === 'attendance' && (
                <Alert title="Attendance grade">Rate = (present + late) ÷ (present + late + absent) × 100. Excused and unrecorded sessions don't count.</Alert>
            )}

            <FormActions>
                <ButtonLink href={routes.performanceAreas.index()} variant="secondary">
                    Cancel
                </ButtonLink>
                <Button type="submit" loading={form.processing}>
                    {submitLabel}
                </Button>
            </FormActions>
        </form>
    );
}
