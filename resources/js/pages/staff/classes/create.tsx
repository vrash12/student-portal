import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/button';
import { FormField, SelectInput, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';

interface PeriodOption {
    id: number;
    name: string;
    isActive: boolean;
}

export default function CreateClass({ periods }: { periods: PeriodOption[] }) {
    const { singular, plural } = terms.classBatch;
    const defaultPeriod = periods.find((period) => period.isActive) ?? periods[0];

    const form = useForm({
        academic_period_id: defaultPeriod ? String(defaultPeriod.id) : '',
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
                    description="The academic period cannot be changed after the class is created."
                    breadcrumbs={[{ label: plural, href: routes.classes.index() }, { label: `Create ${singular}` }]}
                />

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
                        <Button type="submit" loading={form.processing}>
                            Create {singular}
                        </Button>
                    </FormActions>
                </form>
            </div>
        </>
    );
}
