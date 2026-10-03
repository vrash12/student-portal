import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { CampusForm, type CampusFormData, type CampusRow } from '@/components/academic/campus-form';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';

export default function EditCampus({ campus }: { campus: CampusRow }) {
    const form = useForm<CampusFormData>({
        address: campus.address ?? '',
        is_active: campus.isActive,
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put(routes.campuses.update(campus.id), { preserveScroll: true });
    };

    return (
        <>
            <Head title={`Edit ${campus.name}`} />

            <div className="mx-auto flex max-w-3xl flex-col gap-6">
                <PageHeader
                    title={campus.name}
                    description={`Code ${campus.code}. The institution has four fixed campuses; their names and codes do not change.`}
                    breadcrumbs={[{ label: 'Campuses', href: routes.campuses.index() }, { label: campus.name }]}
                />
                <CampusForm form={form} onSubmit={submit} />
            </div>
        </>
    );
}
