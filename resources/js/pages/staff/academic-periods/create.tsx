import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { PeriodForm, type PeriodFormData } from '@/components/academic/period-form';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';

export default function CreateAcademicPeriod() {
    const form = useForm<PeriodFormData>({ name: '', starts_on: '', ends_on: '' });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(routes.academicPeriods.store(), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Create Academic Period" />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title="Create Academic Period"
                    description="New periods start inactive. Set a period active from the list when it begins."
                    breadcrumbs={[{ label: 'Academic Periods', href: routes.academicPeriods.index() }, { label: 'Create Period' }]}
                />
                <PeriodForm form={form} submitLabel="Create Period" onSubmit={submit} />
            </div>
        </>
    );
}
