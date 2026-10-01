import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { SessionDetailsFields, type SessionDetailsData } from '@/components/attendance/session-details-fields';
import { Button, ButtonLink } from '@/components/ui/button';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import type { AttendanceSessionDetails } from '@/types/attendance';

interface EditAttendanceSessionProps {
    session: AttendanceSessionDetails;
    /** Today in the institution's timezone (Y-m-d). */
    today: string;
}

export default function EditAttendanceSession({ session, today }: EditAttendanceSessionProps) {
    const form = useForm<SessionDetailsData>({
        held_on: session.heldOn,
        title: session.title,
        hours: String(session.hours),
        notes: session.notes ?? '',
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put(routes.attendance.sessions.update(session.id), { preserveScroll: true });
    };

    return (
        <>
            <Head title={`Edit ${session.title}`} />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title={`Edit ${session.title}`}
                    description={`${session.classBatch.name} · ${session.classBatch.period}. The ${terms.classBatch.singular.toLowerCase()} of a session cannot be changed.`}
                    breadcrumbs={[
                        { label: 'Attendance', href: routes.attendance.index() },
                        { label: session.title, href: routes.attendance.sessions.show(session.id) },
                        { label: 'Edit' },
                    ]}
                />
                <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                    <FormSection title="Session Details" description="Changing the hours changes the attended hours of every candidate who was present or late.">
                        <SessionDetailsFields data={form.data} errors={form.errors} today={today} onChange={(field, value) => form.setData(field, value)} />
                    </FormSection>
                    <FormActions>
                        <ButtonLink href={routes.attendance.sessions.show(session.id)} variant="secondary">
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
