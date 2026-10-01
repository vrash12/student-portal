import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { ConductTypeForm, type ConductTypeFormData } from '@/components/conduct/conduct-type-form';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';
import type { ConductKindOption } from '@/types/conduct';

interface CreateConductTypeProps {
    kinds: ConductKindOption[];
    nextSortOrder: number;
}

export default function CreateConductType({ kinds, nextSortOrder }: CreateConductTypeProps) {
    const form = useForm<ConductTypeFormData>({
        name: '',
        kind: 'merit',
        default_points: '',
        description: '',
        sort_order: String(nextSortOrder),
        is_active: true,
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        // is_active applies to existing types only; new ones start active.
        form.transform(({ is_active: _isActive, ...data }) => data);
        form.post(routes.conduct.types.store(), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Add Merit or Demerit Type" />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title="Add Merit or Demerit Type"
                    description="A kind of merit or demerit that can be recorded for candidates, with its usual points."
                    breadcrumbs={[
                        { label: 'Merits & Demerits', href: routes.conduct.index() },
                        { label: 'Types', href: routes.conduct.types.index() },
                        { label: 'Add Type' },
                    ]}
                />
                <ConductTypeForm form={form} mode="create" kinds={kinds} submitLabel="Add Type" onSubmit={submit} />
            </div>
        </>
    );
}
