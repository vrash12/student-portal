import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { ConductTypeForm, type ConductTypeFormData } from '@/components/conduct/conduct-type-form';
import { Alert } from '@/components/ui/alert';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';
import type { ConductKindOption, ConductTypeRow } from '@/types/conduct';

interface EditConductTypeProps {
    type: ConductTypeRow;
    kinds: ConductKindOption[];
}

export default function EditConductType({ type, kinds }: EditConductTypeProps) {
    const form = useForm<ConductTypeFormData>({
        name: type.name,
        kind: type.kind.value,
        default_points: String(type.defaultPoints),
        description: type.description ?? '',
        sort_order: String(type.sortOrder),
        is_active: type.isActive,
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put(routes.conduct.types.update(type.id), { preserveScroll: true });
    };

    return (
        <>
            <Head title={`Edit ${type.name}`} />

            <div className="mx-auto flex max-w-3xl flex-col gap-6">
                <PageHeader
                    title={type.name}
                    description={`${type.kind.label} type · used by ${type.entryCount} ${type.entryCount === 1 ? 'entry' : 'entries'}`}
                    breadcrumbs={[
                        { label: 'Merits & Demerits', href: routes.conduct.index() },
                        { label: 'Types', href: routes.conduct.types.index() },
                        { label: type.name },
                    ]}
                />
                {type.entryCount > 0 && (
                    <Alert title="Recorded entries keep their kind and points">
                        Renaming the type changes how its {type.entryCount} recorded {type.entryCount === 1 ? 'entry is' : 'entries are'} labelled. Changing the kind or
                        the usual points only affects entries recorded afterwards.
                    </Alert>
                )}
                <ConductTypeForm form={form} mode="edit" kinds={kinds} submitLabel="Save Changes" onSubmit={submit} />
            </div>
        </>
    );
}
