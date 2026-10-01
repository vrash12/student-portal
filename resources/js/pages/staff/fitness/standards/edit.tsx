import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FitnessEventForm, type FitnessEventFormData, type FitnessEventFormOptions } from '@/components/fitness/event-form';
import { Alert } from '@/components/ui/alert';
import { PageHeader } from '@/components/ui/page-header';
import { formatPoints } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { FitnessPointsRow, FitnessScoringMethod } from '@/types/fitness';

interface EditFitnessEventProps extends FitnessEventFormOptions {
    event: {
        id: number;
        name: string;
        description: string | null;
        unit: { value: 'repetitions' | 'time'; label: string };
        higherIsBetter: boolean;
        method: { value: FitnessScoringMethod; label: string };
        passingPoints: number;
        passingDisplay: string;
        maximumDisplay: string;
        table: FitnessPointsRow[];
        sortOrder: number;
        isActive: boolean;
        testCount: number;
    };
}

export default function EditFitnessEvent({ event, unitOptions, methodOptions, maximumRows }: EditFitnessEventProps) {
    const isTable = event.method.value === 'table';
    const form = useForm<FitnessEventFormData>({
        name: event.name,
        description: event.description ?? '',
        unit: event.unit.value,
        higher_is_better: event.higherIsBetter,
        scoring_method: event.method.value,
        passing_points: formatPoints(event.passingPoints),
        // A table's passing and best results follow from its rows.
        passing_value: isTable ? '' : event.passingDisplay,
        maximum_value: isTable ? '' : event.maximumDisplay,
        points_table: event.table.map((row) => ({ value: row.display, points: formatPoints(row.points) })),
        sort_order: String(event.sortOrder),
        is_active: event.isActive,
    });

    const submit = (submitEvent: FormEvent<HTMLFormElement>) => {
        submitEvent.preventDefault();
        form.put(routes.fitness.standards.update(event.id), { preserveScroll: true });
    };

    return (
        <>
            <Head title={`Edit ${event.name}`} />

            <div className="mx-auto flex max-w-3xl flex-col gap-6">
                <PageHeader
                    title={event.name}
                    description={`Used in ${event.testCount} ${event.testCount === 1 ? 'fitness test' : 'fitness tests'}`}
                    breadcrumbs={[
                        { label: 'Military Fitness', href: routes.fitness.index() },
                        { label: 'Events and Points', href: routes.fitness.standards.index() },
                        { label: event.name },
                    ]}
                />
                {event.testCount > 0 && (
                    <Alert title="Existing tests keep their standards">
                        Changes apply to fitness tests created from now on. The {event.testCount} existing{' '}
                        {event.testCount === 1 ? 'test keeps' : 'tests keep'} the points and standards they were created with, so recorded results do not change.
                    </Alert>
                )}
                <FitnessEventForm
                    form={form}
                    mode="edit"
                    unitOptions={unitOptions}
                    methodOptions={methodOptions}
                    maximumRows={maximumRows}
                    submitLabel="Save Changes"
                    onSubmit={submit}
                />
            </div>
        </>
    );
}
