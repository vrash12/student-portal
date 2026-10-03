import { Head, router, useForm } from '@inertiajs/react';
import { CalendarRange, Layers, Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/button';
import { ConfirmAction } from '@/components/ui/confirm-action';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField, SelectInput, TextInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { formatCalendarDate } from '@/lib/format';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import type { TrainingPhaseSummary } from '@/types/grading';

interface PhaseRow extends TrainingPhaseSummary {
    /** Subjects of classes placed in the phase; a phase in use cannot be deleted. */
    subjectCount: number;
}

interface TrainingPhasesProps {
    /** Academic years, active first. */
    periods: Array<{ id: number; name: string; isActive: boolean }>;
    /** The year shown; null when there is no academic year yet. */
    period: { id: number; name: string; startsOn: string; endsOn: string } | null;
    phases: PhaseRow[];
    /** Suggested next phase; null when the phases already reach the end of the year. */
    next: { number: number; startsOn: string; endsOn: string } | null;
}

interface PhaseFormData {
    number: string;
    name: string;
    starts_on: string;
    ends_on: string;
}

/**
 * Training phases of each academic year (owner request, 2026-10-03: the
 * whole course lasts one year). Phases lie inside the year, in order, without
 * overlapping; the server checks the dates. Each subject of a class is placed
 * in a phase of its class's year on the class's page.
 */
export default function TrainingPhases({ periods, period, phases, next }: TrainingPhasesProps) {
    const classTerm = terms.classBatch.singular.toLowerCase();

    return (
        <>
            <Head title="Training Phases" />

            <div className="mx-auto flex max-w-5xl flex-col gap-6">
                <PageHeader
                    title="Training Phases"
                    description={`The phases of each academic year, in order. Place each subject of a ${classTerm} in a phase on the ${classTerm}'s page.`}
                />

                {period === null ? (
                    <Panel collapsible={false}>
                        <EmptyState
                            icon={CalendarRange}
                            headingLevel="h2"
                            title="No academic year yet"
                            description="Phases belong to an academic year. Create the year first, for example 2026-2027."
                            action={<ButtonLink href={routes.academicPeriods.create()}>Create Academic Year</ButtonLink>}
                        />
                    </Panel>
                ) : (
                    <>
                        <Panel collapsible={false}>
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                                <FormField label="Academic year" className="sm:w-72">
                                    <SelectInput
                                        value={String(period.id)}
                                        onChange={(event) => router.get(routes.trainingPhases.index({ period: event.target.value }), {}, { preserveScroll: true })}
                                    >
                                        {periods.map((option) => (
                                            <option key={option.id} value={String(option.id)}>
                                                {option.name}
                                                {option.isActive ? ' (active)' : ''}
                                            </option>
                                        ))}
                                    </SelectInput>
                                </FormField>
                                <p className="text-sm text-ink-muted sm:pb-2.5">
                                    {formatCalendarDate(period.startsOn)} – {formatCalendarDate(period.endsOn)}. Every phase falls within these dates.
                                </p>
                            </div>
                        </Panel>

                        <Panel title={`Add Phase to ${period.name}`} collapsible={false}>
                            {next === null ? (
                                <p className="text-sm text-ink-muted">
                                    The phases already reach the end of {period.name}. Shorten the last phase to add another.
                                </p>
                            ) : (
                                <PhaseForm
                                    key={`${period.id}-${next.number}-${next.startsOn}`}
                                    period={period}
                                    initial={{ number: String(next.number), name: `Phase ${next.number}`, starts_on: next.startsOn, ends_on: next.endsOn }}
                                    submitLabel="Add Phase"
                                />
                            )}
                        </Panel>

                        <Panel title={`Phases of ${period.name}`} collapsible={false} bodyClassName={phases.length === 0 ? undefined : 'p-0'}>
                            {phases.length === 0 ? (
                                <EmptyState
                                    icon={Layers}
                                    headingLevel="h3"
                                    title="No phases yet"
                                    description="Add the phases of the year above, for example Phase 1, Phase 2 and Phase 3."
                                />
                            ) : (
                                <ul className="divide-y divide-line">
                                    {phases.map((phase) => (
                                        <li key={phase.id} className="flex flex-col gap-3 px-5 py-4 lg:flex-row lg:items-start lg:justify-between">
                                            <PhaseForm
                                                phaseId={phase.id}
                                                period={period}
                                                initial={{ number: String(phase.number), name: phase.name, starts_on: phase.startsOn, ends_on: phase.endsOn }}
                                                submitLabel="Save"
                                            />
                                            <div className="flex items-center gap-3 lg:pt-8">
                                                <span className="text-sm text-ink-muted">
                                                    {phase.subjectCount} {phase.subjectCount === 1 ? 'subject' : 'subjects'}
                                                </span>
                                                <ConfirmAction
                                                    href={routes.trainingPhases.destroy(phase.id)}
                                                    method="delete"
                                                    ariaLabel={`Delete ${phase.name}`}
                                                    icon={<Trash2 className="size-4" aria-hidden="true" />}
                                                    disabled={phase.subjectCount > 0}
                                                    title={`Delete ${phase.name}?`}
                                                    description={<p>No subject is in this phase. It will be removed from {period.name}.</p>}
                                                    confirmLabel="Delete Phase"
                                                >
                                                    Delete
                                                </ConfirmAction>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Panel>
                    </>
                )}
            </div>
        </>
    );
}

/** Adds a phase to the year (no phaseId) or changes one: number, name and dates. */
function PhaseForm({
    phaseId,
    period,
    initial,
    submitLabel,
}: {
    phaseId?: number;
    period: NonNullable<TrainingPhasesProps['period']>;
    initial: PhaseFormData;
    submitLabel: string;
}) {
    const form = useForm<PhaseFormData & { academic_period_id?: string }>(phaseId === undefined ? { ...initial, academic_period_id: String(period.id) } : initial);
    const label = phaseId === undefined ? '' : ` of ${initial.name}`;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => form.setDefaults() };
        if (phaseId === undefined) {
            form.post(routes.trainingPhases.store(), options);
        } else {
            form.put(routes.trainingPhases.update(phaseId), options);
        }
    };

    const srLabel = (text: string) => (
        <>
            {text}
            <span className="sr-only">{label}</span>
        </>
    );

    return (
        <form onSubmit={submit} noValidate className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-start">
            <FormField label={srLabel('Number')} error={form.errors.number} className="sm:w-20">
                <TextInput value={form.data.number} onChange={(event) => form.setData('number', event.target.value)} inputMode="numeric" autoComplete="off" className="tabular-nums" />
            </FormField>
            <FormField label={srLabel('Name')} error={form.errors.name} className="sm:w-48">
                <TextInput value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} maxLength={100} autoComplete="off" />
            </FormField>
            <FormField label={srLabel('Starts')} error={form.errors.starts_on} className="sm:w-40">
                <TextInput type="date" value={form.data.starts_on} min={period.startsOn} max={period.endsOn} onChange={(event) => form.setData('starts_on', event.target.value)} />
            </FormField>
            <FormField label={srLabel('Ends')} error={form.errors.ends_on} className="sm:w-40">
                <TextInput
                    type="date"
                    value={form.data.ends_on}
                    min={form.data.starts_on || period.startsOn}
                    max={period.endsOn}
                    onChange={(event) => form.setData('ends_on', event.target.value)}
                />
            </FormField>
            <Button
                type="submit"
                className="sm:mt-7"
                variant={phaseId === undefined ? 'primary' : 'ghost'}
                loading={form.processing}
                disabled={phaseId !== undefined && !form.isDirty}
                icon={phaseId === undefined ? <Plus className="size-4" aria-hidden="true" /> : undefined}
            >
                {submitLabel}
                {phaseId !== undefined && <span className="sr-only">{label}</span>}
            </Button>
        </form>
    );
}
