import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FitnessEventForm, type FitnessEventFormData, type FitnessEventFormOptions } from '@/components/fitness/event-form';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';

interface CreateFitnessEventProps extends FitnessEventFormOptions {
    nextSortOrder: number;
}

export default function CreateFitnessEvent({ unitOptions, methodOptions, maximumRows, nextSortOrder }: CreateFitnessEventProps) {
    const form = useForm<FitnessEventFormData>({
        name: '',
        description: '',
        unit: 'repetitions',
        higher_is_better: true,
        scoring_method: 'table',
        passing_points: '60',
        passing_value: '',
        maximum_value: '',
        points_table: [{ value: '', points: '' }],
        sort_order: String(nextSortOrder),
        is_active: true,
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        // is_active applies to existing events only.
        form.transform(({ is_active: _isActive, ...data }) => data);
        form.post(routes.fitness.standards.store(), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Add Fitness Event" />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title="Add Fitness Event"
                    description="An event candidates perform in fitness tests, such as push-ups, sit-ups or a timed run, and the points each result earns."
                    breadcrumbs={[
                        { label: 'Military Fitness', href: routes.fitness.index() },
                        { label: 'Events and Points', href: routes.fitness.standards.index() },
                        { label: 'Add Event' },
                    ]}
                />
                <FitnessEventForm
                    form={form}
                    mode="create"
                    unitOptions={unitOptions}
                    methodOptions={methodOptions}
                    maximumRows={maximumRows}
                    submitLabel="Add Event"
                    onSubmit={submit}
                />
            </div>
        </>
    );
}
