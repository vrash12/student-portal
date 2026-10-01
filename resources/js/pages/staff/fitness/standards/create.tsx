import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FitnessEventForm, type FitnessEventFormData } from '@/components/fitness/event-form';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';

interface CreateFitnessEventProps {
    unitOptions: Array<{ value: string; label: string }>;
    nextSortOrder: number;
}

export default function CreateFitnessEvent({ unitOptions, nextSortOrder }: CreateFitnessEventProps) {
    const form = useForm<FitnessEventFormData>({
        name: '',
        description: '',
        unit: 'repetitions',
        higher_is_better: true,
        passing_value: '',
        maximum_value: '',
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
                    description="An event candidates perform in fitness tests, with its passing and maximum standards."
                    breadcrumbs={[
                        { label: 'Military Fitness', href: routes.fitness.index() },
                        { label: 'Standards', href: routes.fitness.standards.index() },
                        { label: 'Add Event' },
                    ]}
                />
                <FitnessEventForm form={form} mode="create" unitOptions={unitOptions} submitLabel="Add Event" onSubmit={submit} />
            </div>
        </>
    );
}
