import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { SessionDetailsFields, type SessionDetailsData } from '@/components/attendance/session-details-fields';
import type { ClassOptionGroup } from '@/components/candidates/candidate-form';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { FormField, SelectInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';

interface CreateAttendanceSessionProps {
    /** Only the classes the user keeps attendance for, active period first. */
    classOptions: ClassOptionGroup[];
    selectedClassId: number | null;
    /** "all": every class; "taught": only the classes the user teaches. */
    scope: 'all' | 'taught';
    /** Today in the institution's timezone (Y-m-d). */
    today: string;
}

interface CreateSessionFormData extends SessionDetailsData {
    class_batch_id: string;
}

export default function CreateAttendanceSession({ classOptions, selectedClassId, scope, today }: CreateAttendanceSessionProps) {
    const { singular, plural } = terms.classBatch;
    const form = useForm<CreateSessionFormData>({
        class_batch_id: String(selectedClassId ?? classOptions[0]?.classes[0]?.id ?? ''),
        held_on: today,
        title: '',
        hours: '',
        notes: '',
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(routes.attendance.sessions.store(), { preserveScroll: true });
    };

    return (
        <>
            <Head title="New Training Session" />

            <div className="mx-auto flex max-w-3xl flex-col gap-6">
                <PageHeader
                    title="New Training Session"
                    description={`A training session of one ${singular.toLowerCase()} on a date. The roll call is taken on the next page.`}
                    breadcrumbs={[{ label: 'Attendance', href: routes.attendance.index() }, { label: 'New Session' }]}
                />

                {classOptions.length === 0 ? (
                    <Alert tone="warning" title={`No ${plural.toLowerCase()} to keep attendance for`}>
                        {scope === 'all'
                            ? `Attendance is kept by ${singular.toLowerCase()}. Create a ${singular.toLowerCase()} and assign its candidates first.`
                            : `Attendance is kept for the ${plural.toLowerCase()} you teach. Ask an administrator to assign you to a ${singular.toLowerCase()}.`}
                    </Alert>
                ) : (
                    <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                        <FormSection title="Session Details">
                            <FormField label={singular} required error={form.errors.class_batch_id} hint={`The ${singular.toLowerCase()} cannot be changed after the session is created.`}>
                                <SelectInput
                                    name="class_batch_id"
                                    value={form.data.class_batch_id}
                                    onChange={(event) => form.setData('class_batch_id', event.target.value)}
                                >
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
                            <SessionDetailsFields data={form.data} errors={form.errors} today={today} onChange={(field, value) => form.setData(field, value)} />
                        </FormSection>

                        <FormActions>
                            <ButtonLink href={routes.attendance.index()} variant="secondary">
                                Cancel
                            </ButtonLink>
                            <Button type="submit" loading={form.processing}>
                                Create Session
                            </Button>
                        </FormActions>
                    </form>
                )}
            </div>
        </>
    );
}
