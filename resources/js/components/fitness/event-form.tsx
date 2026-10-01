import type { InertiaForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { PointsTableEditor, type PointsRowDraft } from '@/components/fitness/points-table-editor';
import { Button, ButtonLink } from '@/components/ui/button';
import { CheckboxField, FormField, TextArea, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { RadioCards } from '@/components/ui/radio-cards';
import { formatPoints } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { FitnessScoringMethod } from '@/types/fitness';

export interface FitnessEventFormData {
    name: string;
    description: string;
    unit: 'repetitions' | 'time';
    higher_is_better: boolean;
    scoring_method: FitnessScoringMethod;
    passing_points: string;
    /** Scaled standards only. */
    passing_value: string;
    maximum_value: string;
    /** Points tables only. */
    points_table: PointsRowDraft[];
    sort_order: string;
    is_active: boolean;
}

export interface FitnessEventFormOptions {
    unitOptions: Array<{ value: string; label: string }>;
    methodOptions: Array<{ value: string; label: string; description: string }>;
    maximumRows: number;
}

interface FitnessEventFormProps extends FitnessEventFormOptions {
    form: InertiaForm<FitnessEventFormData>;
    mode: 'create' | 'edit';
    submitLabel: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}

const directionOptions = [
    { value: 'higher', label: 'Higher is better', description: 'More repetitions or a longer hold score more points.' },
    { value: 'lower', label: 'Lower is better', description: 'A faster (shorter) time scores more points, as in a timed run.' },
];

export function FitnessEventForm({ form, mode, unitOptions, methodOptions, maximumRows, submitLabel, onSubmit }: FitnessEventFormProps) {
    const isTime = form.data.unit === 'time';
    const isTable = form.data.scoring_method === 'table';
    const valueHint = isTime ? 'Minutes:seconds, such as 15:30.' : 'Whole number of repetitions.';
    const passingPoints = Number(form.data.passing_points);
    const passingPointsLabel = Number.isFinite(passingPoints) && form.data.passing_points.trim() !== '' ? formatPoints(passingPoints) : 'passing';
    // Row errors arrive keyed by position ("points_table.3.value").
    const errors = form.errors as Record<string, string | undefined>;

    const changeUnit = (unit: string) => {
        // Times are usually better when lower (a run); repetitions when higher.
        form.setData((current) => ({ ...current, unit: unit === 'time' ? 'time' : 'repetitions', higher_is_better: unit !== 'time' }));
    };

    const changeMethod = (method: string) => {
        form.setData((current) => ({
            ...current,
            scoring_method: method === 'scaled' ? 'scaled' : 'table',
            points_table: method === 'table' && current.points_table.length === 0 ? [{ value: '', points: '' }] : current.points_table,
        }));
    };

    const changeRows = (rows: PointsRowDraft[]) => {
        // Row errors are keyed by position, so they no longer match once a row is added or removed.
        if (rows.length !== form.data.points_table.length) {
            const rowKeys = Object.keys(form.errors).filter((key) => key.startsWith('points_table.')) as Array<keyof FitnessEventFormData>;
            if (rowKeys.length > 0) {
                form.clearErrors(...rowKeys);
            }
        }
        form.setData('points_table', rows);
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
                title="Points"
                description="How a result turns into points (0 to 100). A candidate passes the event with at least the passing points; failing any event fails the test."
            >
                <RadioCards
                    legend="Scoring"
                    name="scoring_method"
                    options={methodOptions}
                    value={form.data.scoring_method}
                    onChange={changeMethod}
                    error={form.errors.scoring_method}
                    required
                />

                <FormField
                    label="Passing points"
                    required
                    error={form.errors.passing_points}
                    hint="The event is passed with at least this many points, for example 60."
                    className="sm:max-w-xs"
                >
                    <TextInput
                        name="passing_points"
                        value={form.data.passing_points}
                        onChange={(event) => form.setData('passing_points', event.target.value)}
                        inputMode="decimal"
                        autoComplete="off"
                        className="tabular-nums"
                    />
                </FormField>

                {isTable ? (
                    <PointsTableEditor
                        rows={form.data.points_table}
                        onChange={changeRows}
                        unit={form.data.unit}
                        higherIsBetter={form.data.higher_is_better}
                        passingPoints={form.data.passing_points}
                        maximumRows={maximumRows}
                        error={form.errors.points_table}
                        rowError={(index) => errors[`points_table.${index}.value`] ?? errors[`points_table.${index}.points`] ?? errors[`points_table.${index}`]}
                    />
                ) : (
                    <div className="grid gap-5 sm:grid-cols-2">
                        <FormField label={`Passing standard (${passingPointsLabel} points)`} required error={form.errors.passing_value} hint={valueHint}>
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
                )}

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
