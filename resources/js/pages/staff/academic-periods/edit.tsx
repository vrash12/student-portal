import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { PeriodForm, type PeriodFormData } from '@/components/academic/period-form';
import { PageHeader } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status-badge';
import { routes } from '@/lib/routes';

interface EditAcademicPeriodProps {
    period: {
        id: number;
        name: string;
        startsOn: string;
        endsOn: string;
        isActive: boolean;
    };
}

export default function EditAcademicPeriod({ period }: EditAcademicPeriodProps) {
    const form = useForm<PeriodFormData>({ name: period.name, starts_on: period.startsOn, ends_on: period.endsOn });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put(routes.academicPeriods.update(period.id), { preserveScroll: true });
    };

    return (
        <>
            <Head title={`Edit ${period.name}`} />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title={period.name}
                    description={
                        period.isActive ? (
                            <StatusBadge tone="success">Active period</StatusBadge>
                        ) : (
                            <StatusBadge tone="neutral">Inactive period</StatusBadge>
                        )
                    }
                    breadcrumbs={[{ label: 'Academic Periods', href: routes.academicPeriods.index() }, { label: period.name }]}
                />
                <PeriodForm form={form} submitLabel="Save Changes" onSubmit={submit} />
            </div>
        </>
    );
}
