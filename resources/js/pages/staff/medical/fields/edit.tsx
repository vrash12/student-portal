import { Head, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { MedicalFieldForm, type MedicalFieldFormData } from '@/components/medical/medical-field-form';
import { Alert } from '@/components/ui/alert';
import { ConfirmAction } from '@/components/ui/confirm-action';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';
import type { MedicalFieldDefinition, MedicalFieldTypeOption } from '@/types/medical';

interface EditMedicalFieldProps {
    field: MedicalFieldDefinition & { valueCount: number };
    types: MedicalFieldTypeOption[];
}

export default function EditMedicalField({ field, types }: EditMedicalFieldProps) {
    const form = useForm<MedicalFieldFormData>({
        name: field.name,
        field_type: field.type.value,
        options: field.options.join('\n'),
        help_text: field.helpText ?? '',
        sort_order: String(field.sortOrder),
        visible_to_instructors: field.visibleToInstructors,
        visible_to_candidate: field.visibleToCandidate,
        is_active: field.isActive,
    });
    const inUse = field.valueCount > 0;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put(routes.medical.fields.update(field.id), { preserveScroll: true });
    };

    return (
        <>
            <Head title={`Edit ${field.name}`} />

            <div className="mx-auto flex max-w-3xl flex-col gap-6">
                <PageHeader
                    title={field.name}
                    description={`${field.type.label} · recorded for ${field.valueCount} ${field.valueCount === 1 ? 'candidate' : 'candidates'}`}
                    breadcrumbs={[
                        { label: 'Medical Records', href: routes.medical.records.index() },
                        { label: 'Fields', href: routes.medical.fields.index() },
                        { label: field.name },
                    ]}
                    actions={
                        !inUse && (
                            <ConfirmAction
                                href={routes.medical.fields.destroy(field.id)}
                                method="delete"
                                variant="secondary"
                                size="md"
                                icon={<Trash2 className="size-4" aria-hidden="true" />}
                                title={`Delete ${field.name}?`}
                                description={<p>No candidate has a value for this field, so it can be deleted. The deletion is kept in the audit log.</p>}
                                confirmLabel="Delete Field"
                            >
                                Delete Field
                            </ConfirmAction>
                        )
                    }
                />
                {inUse && (
                    <Alert title="This field is in use">
                        Renaming it or changing who sees it applies to every recorded value. To stop using it, make it inactive: the {field.valueCount}{' '}
                        recorded {field.valueCount === 1 ? 'value is' : 'values are'} kept.
                    </Alert>
                )}
                <MedicalFieldForm form={form} mode="edit" types={types} typeLocked={inUse} submitLabel="Save Changes" onSubmit={submit} />
            </div>
        </>
    );
}
