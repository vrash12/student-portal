import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { NoticeFields, type NoticeFieldsData } from '@/components/announcements/notice-fields';
import { Button, ButtonLink } from '@/components/ui/button';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { PageHeader } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status-badge';
import { routes } from '@/lib/routes';
import type { AnnouncementStatus } from '@/types/announcements';

interface EditAnnouncementProps {
    announcement: {
        id: number;
        title: string;
        body: string;
        isImportant: boolean;
        /** Who sees it (fixed). */
        audience: string;
        status: AnnouncementStatus;
        /** In the institution's timezone, as the date-and-time inputs expect them. */
        publishesAt: string;
        expiresAt: string | null;
    };
}

export default function EditAnnouncement({ announcement }: EditAnnouncementProps) {
    const form = useForm<NoticeFieldsData>({
        title: announcement.title,
        body: announcement.body,
        is_important: announcement.isImportant,
        publishes_at: announcement.publishesAt,
        expires_at: announcement.expiresAt ?? '',
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put(routes.announcements.update(announcement.id), { preserveScroll: true });
    };

    return (
        <>
            <Head title={`Edit ${announcement.title}`} />

            <div className="mx-auto flex max-w-3xl flex-col gap-6">
                <PageHeader
                    title={`Edit ${announcement.title}`}
                    description={`To ${announcement.audience}. Who sees a notice cannot be changed: to reach other candidates, withdraw it and post a new one.`}
                    breadcrumbs={[{ label: 'Announcements', href: routes.announcements.index() }, { label: 'Edit Notice' }]}
                    actions={<StatusBadge tone={announcement.status.tone}>{announcement.status.label}</StatusBadge>}
                />
                <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                    <FormSection title="Notice" description="Candidates see the change right away while the notice is showing.">
                        <NoticeFields data={form.data} errors={form.errors} creating={false} onChange={(field, value) => form.setData((data) => ({ ...data, [field]: value }))} />
                    </FormSection>
                    <FormActions>
                        <ButtonLink href={routes.announcements.index()} variant="secondary">
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
