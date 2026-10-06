import { Head, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { ScheduleEntryFields, type ScheduleClassOption, type ScheduleEntryData, type ScheduleInstructorOption } from '@/components/schedule/schedule-entry-fields';
import { Button, ButtonLink } from '@/components/ui/button';
import { ConfirmAction } from '@/components/ui/confirm-action';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';

interface EditScheduleEntryProps {
    /** Only the entry's class (it never changes). */
    classOptions: ScheduleClassOption[];
    instructors: ScheduleInstructorOption[];
    entry: {
        id: number;
        classBatch: { id: number; name: string };
        classSubjectId: number | null;
        instructorId: number | null;
        title: string;
        location: string | null;
        startsOn: string;
        repeatsWeekly: boolean;
        endsOn: string | null;
        startTime: string;
        endTime: string;
        notes: string | null;
    };
}

export default function EditScheduleEntry({ classOptions, instructors, entry }: EditScheduleEntryProps) {
    const form = useForm<ScheduleEntryData>({
        class_subject_id: entry.classSubjectId === null ? '' : String(entry.classSubjectId),
        instructor_id: entry.instructorId === null ? '' : String(entry.instructorId),
        title: entry.title,
        location: entry.location ?? '',
        starts_on: entry.startsOn,
        repeats_weekly: entry.repeatsWeekly,
        ends_on: entry.endsOn ?? '',
        start_time: entry.startTime,
        end_time: entry.endTime,
        notes: entry.notes ?? '',
    });
    const backHref = routes.schedule.index({ view: 'class', class: String(entry.classBatch.id), week: entry.startsOn });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put(routes.schedule.entries.update(entry.id), { preserveScroll: true });
    };

    return (
        <>
            <Head title={`Edit ${entry.title}`} />

            <div className="mx-auto flex max-w-3xl flex-col gap-6">
                <PageHeader
                    title={`Edit ${entry.title}`}
                    description={`${entry.classBatch.name}. The ${terms.classBatch.singular.toLowerCase()} cannot be changed. A change to a weekly session applies to every week.`}
                    breadcrumbs={[{ label: 'Training Schedule', href: backHref }, { label: 'Edit' }]}
                    actions={
                        <ConfirmAction
                            method="delete"
                            href={routes.schedule.entries.destroy(entry.id)}
                            variant="secondary"
                            size="md"
                            icon={<Trash2 className="size-4" aria-hidden="true" />}
                            title="Remove from the schedule?"
                            description={entry.repeatsWeekly ? `"${entry.title}" will be removed from every week.` : `"${entry.title}" will be removed from the schedule.`}
                            confirmLabel="Remove"
                        >
                            Remove
                        </ConfirmAction>
                    }
                />
                <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                    <FormSection title="Session">
                        <ScheduleEntryFields
                            data={form.data}
                            errors={form.errors}
                            classOption={classOptions[0] ?? null}
                            instructors={instructors}
                            onChange={(changes) => form.setData((data) => ({ ...data, ...changes }))}
                        />
                    </FormSection>
                    <FormActions>
                        <ButtonLink href={backHref} variant="secondary">
                            Cancel
                        </ButtonLink>
                        <Button type="submit" loading={form.processing}>
                            Save Changes
                        </Button>
                    </FormActions>
                </form>
            </div>
        </>
    );
}
