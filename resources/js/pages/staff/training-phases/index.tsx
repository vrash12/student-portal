import { Head, useForm } from '@inertiajs/react';
import { Layers, Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { ConfirmAction } from '@/components/ui/confirm-action';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField, TextInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import type { TrainingPhaseSummary } from '@/types/grading';

interface PhaseRow extends TrainingPhaseSummary {
    /** Subjects of classes placed in the phase; a phase in use cannot be deleted. */
    subjectCount: number;
}

interface TrainingPhasesProps {
    phases: PhaseRow[];
    nextNumber: number;
}

/**
 * Training phases of the course (owner request, 2026-10-03). Each subject of
 * a class is placed in a phase on the class's page; phase averages and the
 * CGPA are calculated by the server.
 */
export default function TrainingPhases({ phases, nextNumber }: TrainingPhasesProps) {
    const classTerm = terms.classBatch.singular.toLowerCase();

    return (
        <>
            <Head title="Training Phases" />

            <div className="mx-auto flex max-w-4xl flex-col gap-6">
                <PageHeader
                    title="Training Phases"
                    description={`The phases of the course, in order. Place each subject of a ${classTerm} in a phase on the ${classTerm}'s page; candidates then get an average per phase and a CGPA.`}
                />

                <Panel title="Add Phase" collapsible={false}>
                    <PhaseForm key={nextNumber} initial={{ number: String(nextNumber), name: `Phase ${nextNumber}` }} submitLabel="Add Phase" />
                </Panel>

                <Panel title="Phases" collapsible={false} bodyClassName={phases.length === 0 ? undefined : 'p-0'}>
                    {phases.length === 0 ? (
                        <EmptyState icon={Layers} headingLevel="h3" title="No phases yet" description="Add the phases of the course above, for example Phase 1, Phase 2 and Phase 3." />
                    ) : (
                        <ul className="divide-y divide-line">
                            {phases.map((phase) => (
                                <li key={phase.id} className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-end sm:justify-between">
                                    <PhaseForm phaseId={phase.id} initial={{ number: String(phase.number), name: phase.name }} submitLabel="Save" />
                                    <div className="flex items-center gap-3 sm:pb-1">
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
                                            description={<p>No subject is in this phase. It will be removed from the list.</p>}
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
            </div>
        </>
    );
}

/** Adds a phase (no phaseId) or renames and renumbers one. */
function PhaseForm({ phaseId, initial, submitLabel }: { phaseId?: number; initial: { number: string; name: string }; submitLabel: string }) {
    const form = useForm(initial);
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

    return (
        <form onSubmit={submit} noValidate className="flex flex-col gap-2 sm:flex-row sm:items-end">
            <FormField
                label={
                    <>
                        Number<span className="sr-only">{label}</span>
                    </>
                }
                error={form.errors.number}
                className="sm:w-24"
            >
                <TextInput value={form.data.number} onChange={(event) => form.setData('number', event.target.value)} inputMode="numeric" autoComplete="off" className="tabular-nums" />
            </FormField>
            <FormField
                label={
                    <>
                        Name<span className="sr-only">{label}</span>
                    </>
                }
                error={form.errors.name}
                className="sm:w-72"
            >
                <TextInput value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} maxLength={100} autoComplete="off" />
            </FormField>
            <Button
                type="submit"
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
