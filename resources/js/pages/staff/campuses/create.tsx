import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { CampusForm, type CampusFormData } from '@/components/academic/campus-form';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';

export default function CreateCampus() {
    const form = useForm<CampusFormData>({
        name: '',
        code: '',
        address: '',
        is_active: true,
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        // is_active applies to existing campuses only; new ones start active.
        form.transform(({ is_active: _isActive, ...data }) => data);
        form.post(routes.campuses.store(), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Add Campus" />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title="Add Campus"
                    description="A campus of the institution."
                    breadcrumbs={[{ label: 'Campuses', href: routes.campuses.index() }, { label: 'Add Campus' }]}
                />
                <CampusForm form={form} mode="create" submitLabel="Add Campus" onSubmit={submit} />
            </div>
        </>
    );
}
