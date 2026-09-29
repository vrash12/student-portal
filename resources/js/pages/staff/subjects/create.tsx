import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { SubjectForm, type SubjectFormData } from '@/components/academic/subject-form';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';

export default function CreateSubject() {
    const form = useForm<SubjectFormData>({ code: '', name: '', description: '', is_active: true });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(routes.subjects.store(), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Create Subject" />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title="Create Subject"
                    breadcrumbs={[{ label: 'Subjects', href: routes.subjects.index() }, { label: 'Create Subject' }]}
                />
                <SubjectForm form={form} mode="create" submitLabel="Create Subject" onSubmit={submit} />
            </div>
        </>
    );
}
