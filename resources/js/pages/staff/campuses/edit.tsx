import { Head, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { CampusForm, type CampusFormData, type CampusRow } from '@/components/academic/campus-form';
import { ConfirmAction } from '@/components/ui/confirm-action';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { routes } from '@/lib/routes';

interface EditCampusProps {
    campus: CampusRow;
    /** Classes, candidates, staff or history refer to the campus: it can only be deactivated. */
    inUse: boolean;
}

export default function EditCampus({ campus, inUse }: EditCampusProps) {
    const form = useForm<CampusFormData>({
        name: campus.name,
        code: campus.code,
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
                <PageHeader title={campus.name} breadcrumbs={[{ label: 'Campuses', href: routes.campuses.index() }, { label: campus.name }]} />
                <CampusForm form={form} mode="edit" submitLabel="Save Changes" onSubmit={submit} />

                <Panel title="Remove campus">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-sm text-ink-muted">
                            {inUse
                                ? 'This campus has classes, candidates, staff or history, so it cannot be removed. Deactivate it instead.'
                                : 'This campus was never used, so it can be removed.'}
                        </p>
                        <ConfirmAction
                            href={routes.campuses.destroy(campus.id)}
                            method="delete"
                            variant="danger"
                            icon={<Trash2 className="size-4" aria-hidden="true" />}
                            disabled={inUse}
                            title="Remove campus?"
                            description={<p>{campus.name} will be removed. This cannot be undone.</p>}
                            confirmLabel="Remove Campus"
                        >
                            Remove Campus
                        </ConfirmAction>
                    </div>
                </Panel>
            </div>
        </>
    );
}
