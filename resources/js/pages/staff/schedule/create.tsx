import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { ScheduleEntryFields, type ScheduleClassOption, type ScheduleEntryData, type ScheduleInstructorOption } from '@/components/schedule/schedule-entry-fields';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { FormField, SelectInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';

interface CreateScheduleEntryProps {
    /** Classes the user may schedule, active academic year first. */
    classOptions: ScheduleClassOption[];
    instructors: ScheduleInstructorOption[];
    selectedClassId: number | null;
    /** Pre-filled date (Y-m-d). */
    date: string;
}

interface CreateScheduleEntryData extends ScheduleEntryData {
    class_batch_id: string;
}

export default function CreateScheduleEntry({ classOptions, instructors, selectedClassId, date }: CreateScheduleEntryProps) {
    const { singular, plural } = terms.classBatch;
    const form = useForm<CreateScheduleEntryData>({
        class_batch_id: String(selectedClassId ?? classOptions[0]?.id ?? ''),
        class_subject_id: '',
        instructor_id: '',
        title: '',
        location: '',
        starts_on: date,
        repeats_weekly: false,
        ends_on: '',
        start_time: '08:00',
        end_time: '09:00',
        notes: '',
    });
    const classOption = classOptions.find((option) => String(option.id) === form.data.class_batch_id) ?? null;
    const backHref = routes.schedule.index({ view: 'class', class: form.data.class_batch_id });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(routes.schedule.entries.store(), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Add to Schedule" />

            <div className="mx-auto flex max-w-3xl flex-col gap-6">
                <PageHeader
                    title="Add to Schedule"
                    description={`A session of one ${singular.toLowerCase()}, once or every week. Candidates of the ${singular.toLowerCase()} see it on their portal.`}
                    breadcrumbs={[{ label: 'Training Schedule', href: routes.schedule.index() }, { label: 'Add' }]}
                />

                {classOptions.length === 0 ? (
                    <Alert tone="warning" title={`No ${plural.toLowerCase()} to schedule`}>
                        Create a {singular.toLowerCase()} first, or ask an administrator for access to its schedule.
                    </Alert>
                ) : (
                    <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                        <FormSection title="Session">
                            <FormField label={singular} required error={form.errors.class_batch_id} hint={`The ${singular.toLowerCase()} cannot be changed afterwards.`}>
                                <SelectInput
                                    name="class_batch_id"
                                    value={form.data.class_batch_id}
                                    onChange={(event) => form.setData((data) => ({ ...data, class_batch_id: event.target.value, class_subject_id: '', instructor_id: '' }))}
                                >
                                    {classOptions.map((option) => (
                                        <option key={option.id} value={String(option.id)}>
                                            {option.name} · {option.period.name}
                                            {option.period.isActive ? ' (active)' : ''}
                                        </option>
                                    ))}
                                </SelectInput>
                            </FormField>
                            <ScheduleEntryFields
                                data={form.data}
                                errors={form.errors}
                                classOption={classOption}
                                instructors={instructors}
                                onChange={(changes) => form.setData((data) => ({ ...data, ...changes }))}
                            />
                        </FormSection>

                        <FormActions>
                            <ButtonLink href={backHref} variant="secondary">
                                Cancel
                            </ButtonLink>
                            <Button type="submit" loading={form.processing}>
                                Add to Schedule
                            </Button>
                        </FormActions>
                    </form>
                )}
            </div>
        </>
    );
}
