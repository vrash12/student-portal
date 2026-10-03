import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { FormField, SelectInput, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { PageHeader } from '@/components/ui/page-header';
import { Permission, usePermissions } from '@/lib/permissions';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import type { CampusOption } from '@/types';

interface PeriodOption {
    id: number;
    name: string;
    isActive: boolean;
}

interface CreateClassProps {
    periods: PeriodOption[];
    /** Active campuses the user may place a class on. */
    campusOptions: CampusOption[];
}

export default function CreateClass({ periods, campusOptions }: CreateClassProps) {
    const { singular, plural } = terms.classBatch;
    const { can } = usePermissions();
    const defaultPeriod = periods.find((period) => period.isActive) ?? periods[0];
    const onlyCampus = campusOptions.length === 1 ? campusOptions[0] : undefined;

    const form = useForm({
        academic_period_id: defaultPeriod ? String(defaultPeriod.id) : '',
        campus_id: onlyCampus ? String(onlyCampus.id) : '',
        name: '',
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(routes.classes.store(), { preserveScroll: true });
    };

    return (
        <>
            <Head title={`Create ${singular}`} />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title={`Create ${singular}`}
                    description="The academic period and the campus cannot be changed after the class is created."
                    breadcrumbs={[{ label: plural, href: routes.classes.index() }, { label: `Create ${singular}` }]}
                />

                {campusOptions.length === 0 && (
                    <Alert tone="warning" title="No active campus" className="mb-6">
                        Every {singular.toLowerCase()} belongs to a campus.{' '}
                        {can(Permission.ManageCampuses) ? (
                            <Link href={routes.campuses.index()} className="text-primary-700 underline">
                                Switch a campus on first.
                            </Link>
                        ) : (
                            'Ask an administrator to switch a campus on first.'
                        )}
                    </Alert>
                )}

                <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                    <FormSection title={`${singular} Details`}>
                        <FormField label="Academic Period" required error={form.errors.academic_period_id}>
                            <SelectInput
                                name="academic_period_id"
                                value={form.data.academic_period_id}
                                onChange={(event) => form.setData('academic_period_id', event.target.value)}
                            >
                                {periods.map((period) => (
                                    <option key={period.id} value={String(period.id)}>
                                        {period.name}
                                        {period.isActive ? ' (active)' : ''}
                                    </option>
                                ))}
                            </SelectInput>
                        </FormField>

                        {campusOptions.length > 1 ? (
                            <FormField label="Campus" required error={form.errors.campus_id}>
                                <SelectInput name="campus_id" value={form.data.campus_id} onChange={(event) => form.setData('campus_id', event.target.value)}>
                                    <option value="">Choose a campus</option>
                                    {campusOptions.map((campus) => (
                                        <option key={campus.id} value={String(campus.id)}>
                                            {campus.name}
                                        </option>
                                    ))}
                                </SelectInput>
                            </FormField>
                        ) : (
                            onlyCampus && (
                                <div className="text-sm">
                                    <p className="font-medium text-ink">Campus</p>
                                    <p className="mt-1 text-ink-muted">{onlyCampus.name}</p>
                                    {form.errors.campus_id && <p className="mt-1 text-danger-fg">{form.errors.campus_id}</p>}
                                </div>
                            )
                        )}

                        <FormField label="Name" required error={form.errors.name} hint="For example: Sample Batch A.">
                            <TextInput
                                name="name"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                maxLength={100}
                                autoComplete="off"
                            />
                        </FormField>
                    </FormSection>

                    <FormActions>
                        <ButtonLink href={routes.classes.index()} variant="secondary">
                            Cancel
                        </ButtonLink>
                        <Button type="submit" loading={form.processing} disabled={campusOptions.length === 0}>
                            Create {singular}
                        </Button>
                    </FormActions>
                </form>
            </div>
        </>
    );
}
