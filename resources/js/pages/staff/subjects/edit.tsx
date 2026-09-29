import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { SubjectForm, type SubjectFormData } from '@/components/academic/subject-form';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';

interface EditSubjectProps {
    subject: {
        id: number;
        code: string;
        name: string;
        description: string | null;
        isActive: boolean;
        classCount: number;
    };
}

export default function EditSubject({ subject }: EditSubjectProps) {
    const form = useForm<SubjectFormData>({
        code: subject.code,
        name: subject.name,
        description: subject.description ?? '',
        is_active: subject.isActive,
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put(routes.subjects.update(subject.id), { preserveScroll: true });
    };

    return (
        <>
            <Head title={`Edit ${subject.name}`} />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title={subject.name}
                    description={`${subject.code} · Taken by ${subject.classCount} ${subject.classCount === 1 ? 'class' : 'classes'}`}
                    breadcrumbs={[{ label: 'Subjects', href: routes.subjects.index() }, { label: subject.name }]}
                />
                <SubjectForm form={form} mode="edit" submitLabel="Save Changes" onSubmit={submit} />
            </div>
        </>
    );
}
