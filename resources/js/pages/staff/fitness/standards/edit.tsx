import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FitnessEventForm, type FitnessEventFormData } from '@/components/fitness/event-form';
import { Alert } from '@/components/ui/alert';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';

interface EditFitnessEventProps {
    event: {
        id: number;
        name: string;
        description: string | null;
        unit: { value: string; label: string };
        higherIsBetter: boolean;
        passingDisplay: string;
        maximumDisplay: string;
        sortOrder: number;
        isActive: boolean;
        testCount: number;
    };
    unitOptions: Array<{ value: string; label: string }>;
}

export default function EditFitnessEvent({ event, unitOptions }: EditFitnessEventProps) {
    const form = useForm<FitnessEventFormData>({
        name: event.name,
        description: event.description ?? '',
        unit: event.unit.value,
        higher_is_better: event.higherIsBetter,
        passing_value: event.passingDisplay,
        maximum_value: event.maximumDisplay,
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
                        { label: 'Standards', href: routes.fitness.standards.index() },
                        { label: event.name },
                    ]}
                />
                {event.testCount > 0 && (
                    <Alert title="Existing tests keep their standards">
                        Changes apply to fitness tests created from now on. The {event.testCount} existing{' '}
                        {event.testCount === 1 ? 'test keeps' : 'tests keep'} the standards they were created with, so recorded results do not change.
                    </Alert>
                )}
                <FitnessEventForm form={form} mode="edit" unitOptions={unitOptions} submitLabel="Save Changes" onSubmit={submit} />
            </div>
        </>
    );
}
