import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { MedicalFieldForm, type MedicalFieldFormData } from '@/components/medical/medical-field-form';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';
import type { MedicalFieldTypeOption } from '@/types/medical';

interface CreateMedicalFieldProps {
    types: MedicalFieldTypeOption[];
    nextSortOrder: number;
}

export default function CreateMedicalField({ types, nextSortOrder }: CreateMedicalFieldProps) {
    const form = useForm<MedicalFieldFormData>({
        name: '',
        field_type: 'text',
        options: '',
        help_text: '',
        sort_order: String(nextSortOrder),
        visible_to_instructors: false,
        visible_to_candidate: true,
        is_active: true,
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        // is_active applies to existing fields only; new ones start active.
        form.transform(({ is_active: _isActive, ...data }) => data);
        form.post(routes.medical.fields.store(), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Add Medical Record Field" />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title="Add Medical Record Field"
                    description="A question every candidate's medical record answers, such as blood type or allergies."
                    breadcrumbs={[
                        { label: 'Medical Records', href: routes.medical.records.index() },
                        { label: 'Fields', href: routes.medical.fields.index() },
                        { label: 'Add Field' },
                    ]}
                />
                <MedicalFieldForm form={form} mode="create" types={types} typeLocked={false} submitLabel="Add Field" onSubmit={submit} />
            </div>
        </>
    );
}
