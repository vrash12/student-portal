import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import type { ClassOptionGroup } from '@/components/candidates/candidate-form';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { CheckboxField, FormField, SelectInput, TextArea, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';

interface EventOption {
    id: number;
    name: string;
    unitLabel: string;
    passingDisplay: string;
    maximumDisplay: string;
}

interface CreateFitnessTestProps {
    classOptions: ClassOptionGroup[];
    events: EventOption[];
    /** Today in the institution's timezone (Y-m-d). */
    today: string;
}

interface FitnessTestFormData {
    class_batch_id: string;
    title: string;
    tested_on: string;
    notes: string;
    event_ids: number[];
}

export default function CreateFitnessTest({ classOptions, events, today }: CreateFitnessTestProps) {
    const { singular } = terms.classBatch;
    const form = useForm<FitnessTestFormData>({
        class_batch_id: String(classOptions[0]?.classes[0]?.id ?? ''),
        title: '',
        tested_on: today,
        notes: '',
        event_ids: events.map((event) => event.id),
    });
    const eventErrors = Object.entries(form.errors)
        .filter(([key]) => key === 'event_ids' || key.startsWith('event_ids.'))
        .map(([, message]) => message);

    const toggleEvent = (eventId: number, checked: boolean) => {
        form.setData('event_ids', checked ? [...form.data.event_ids, eventId] : form.data.event_ids.filter((id) => id !== eventId));
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(routes.fitness.tests.store(), { preserveScroll: true });
    };

    return (
        <>
            <Head title="New Fitness Test" />

            <div className="mx-auto flex max-w-3xl flex-col gap-6">
                <PageHeader
                    title="New Fitness Test"
                    description={`A fitness test of one ${singular.toLowerCase()} on a date. Results are recorded on the next page.`}
                    breadcrumbs={[{ label: 'Military Fitness', href: routes.fitness.index() }, { label: 'New Test' }]}
                />

                {events.length === 0 ? (
                    <Alert tone="warning" title="No active fitness events">
                        Add the fitness events and their standards before creating a test.
                        <div className="mt-2">
                            <ButtonLink href={routes.fitness.standards.create()} variant="secondary">
                                Add Fitness Event
                            </ButtonLink>
                        </div>
                    </Alert>
                ) : (
                    <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                        <FormSection title="Test Details">
                            <div className="grid gap-5 sm:grid-cols-2">
                                <FormField label={singular} required error={form.errors.class_batch_id}>
                                    <SelectInput
                                        name="class_batch_id"
                                        value={form.data.class_batch_id}
                                        onChange={(event) => form.setData('class_batch_id', event.target.value)}
                                    >
                                        {classOptions.length === 0 && <option value="">No {terms.classBatch.plural.toLowerCase()} yet</option>}
                                        {classOptions.map((group) => (
                                            <optgroup key={group.period} label={`${group.period}${group.isActive ? ' (active)' : ''}`}>
                                                {group.classes.map((classBatch) => (
                                                    <option key={classBatch.id} value={String(classBatch.id)}>
                                                        {classBatch.name}
                                                    </option>
                                                ))}
                                            </optgroup>
                                        ))}
                                    </SelectInput>
                                </FormField>
                                <FormField label="Test date" required error={form.errors.tested_on}>
                                    <TextInput type="date" name="tested_on" value={form.data.tested_on} onChange={(event) => form.setData('tested_on', event.target.value)} />
                                </FormField>
                            </div>
                            <FormField label="Title" required error={form.errors.title} hint="e.g. Diagnostic Fitness Test, Fitness Test 1.">
                                <TextInput name="title" value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} maxLength={150} autoComplete="off" />
                            </FormField>
                            <FormField label="Notes" error={form.errors.notes} hint="Optional. Conditions, location, or test officer. Up to 500 characters.">
                                <TextArea name="notes" value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} maxLength={500} rows={2} />
                            </FormField>
                        </FormSection>

                        <FormSection
                            title="Events"
                            description="The test keeps these events' current standards, even if the standards change later."
                        >
                            <div className="flex flex-col gap-3">
                                {events.map((event) => (
                                    <CheckboxField
                                        key={event.id}
                                        label={event.name}
                                        description={`${event.unitLabel} · passing ${event.passingDisplay} (60 pts) · maximum ${event.maximumDisplay} (100 pts)`}
                                        checked={form.data.event_ids.includes(event.id)}
                                        onChange={(changeEvent) => toggleEvent(event.id, changeEvent.target.checked)}
                                    />
                                ))}
                            </div>
                            {eventErrors.length > 0 && (
                                <p role="alert" className="text-sm text-danger-fg">
                                    {eventErrors[0]}
                                </p>
                            )}
                        </FormSection>

                        <FormActions>
                            <ButtonLink href={routes.fitness.index()} variant="secondary">
                                Cancel
                            </ButtonLink>
                            <Button type="submit" loading={form.processing}>
                                Create Test
                            </Button>
                        </FormActions>
                    </form>
                )}
            </div>
        </>
    );
}
