import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { NoticeFields, type NoticeFieldsData } from '@/components/announcements/notice-fields';
import type { ClassOptionGroup } from '@/components/candidates/candidate-form';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { FormField, SelectInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { PageHeader } from '@/components/ui/page-header';
import { RadioCards } from '@/components/ui/radio-cards';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import type { CampusOption } from '@/types';
import type { AnnouncementAudienceValue, AudienceOption } from '@/types/announcements';

interface CreateAnnouncementProps {
    /** Only the audiences this user may post to. */
    audiences: AudienceOption[];
    campusOptions: CampusOption[];
    /** Classes this user may post to, active period first. */
    classOptions: ClassOptionGroup[];
}

interface CreateNoticeFormData extends NoticeFieldsData {
    audience: AnnouncementAudienceValue | '';
    campus_id: string;
    class_batch_id: string;
}

export default function CreateAnnouncement({ audiences, campusOptions, classOptions }: CreateAnnouncementProps) {
    const { singular } = terms.classBatch;
    const form = useForm<CreateNoticeFormData>({
        audience: audiences.length === 1 ? (audiences[0]?.value ?? '') : '',
        campus_id: campusOptions.length === 1 ? String(campusOptions[0]?.id ?? '') : '',
        class_batch_id: '',
        title: '',
        body: '',
        is_important: false,
        publishes_at: '',
        expires_at: '',
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            // Only the target the chosen audience needs is sent.
            campus_id: data.audience === 'campus' ? data.campus_id : '',
            class_batch_id: data.audience === 'class' ? data.class_batch_id : '',
        }));
        form.post(routes.announcements.store(), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Post Notice" />

            <div className="mx-auto flex max-w-3xl flex-col gap-6">
                <PageHeader
                    title="Post Notice"
                    description="Candidates see the notice on their portal home page while it is showing. Who sees it cannot be changed after it is posted."
                    breadcrumbs={[{ label: 'Announcements', href: routes.announcements.index() }, { label: 'Post Notice' }]}
                />

                {audiences.length === 0 ? (
                    <Alert tone="warning" title="No candidates to post to">
                        Notices go to the {terms.classBatch.plural.toLowerCase()} you teach. Ask an administrator to assign you to a {singular.toLowerCase()}.
                    </Alert>
                ) : (
                    <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                        <FormSection title="Who Sees It">
                            <RadioCards
                                legend="Audience"
                                name="audience"
                                required
                                columns={audiences.length === 3 ? 3 : 2}
                                options={audiences.map((audience) => ({ value: audience.value, label: audience.label, description: audience.description }))}
                                value={form.data.audience}
                                onChange={(value) => form.setData('audience', value as AnnouncementAudienceValue)}
                                error={form.errors.audience}
                            />
                            {form.data.audience === 'campus' && (
                                <FormField label="Campus" required error={form.errors.campus_id}>
                                    <SelectInput name="campus_id" value={form.data.campus_id} onChange={(event) => form.setData('campus_id', event.target.value)}>
                                        <option value="">Choose a campus</option>
                                        {campusOptions.map((campus) => (
                                            <option key={campus.id} value={String(campus.id)}>
                                                {campus.name}
                                                {campus.isActive ? '' : ' (switched off)'}
                                            </option>
                                        ))}
                                    </SelectInput>
                                </FormField>
                            )}
                            {form.data.audience === 'class' && (
                                <FormField label={singular} required error={form.errors.class_batch_id}>
                                    <SelectInput name="class_batch_id" value={form.data.class_batch_id} onChange={(event) => form.setData('class_batch_id', event.target.value)}>
                                        <option value="">Choose a {singular.toLowerCase()}</option>
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
                            )}
                        </FormSection>

                        <FormSection title="Notice">
                            <NoticeFields data={form.data} errors={form.errors} creating onChange={(field, value) => form.setData((data) => ({ ...data, [field]: value }))} />
                        </FormSection>

                        <FormActions>
                            <ButtonLink href={routes.announcements.index()} variant="secondary">
                                Cancel
                            </ButtonLink>
                            <Button type="submit" loading={form.processing}>
                                Post Notice
                            </Button>
                        </FormActions>
                    </form>
                )}
            </div>
        </>
    );
}
