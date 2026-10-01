import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/button';
import { FormField, TextArea, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';

interface EditFitnessTestProps {
    test: {
        id: number;
        title: string;
        testedOn: string;
        notes: string | null;
        classBatch: { id: number; name: string; period: string };
    };
}

export default function EditFitnessTest({ test }: EditFitnessTestProps) {
    const form = useForm({ title: test.title, tested_on: test.testedOn, notes: test.notes ?? '' });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put(routes.fitness.tests.update(test.id), { preserveScroll: true });
    };

    return (
        <>
            <Head title={`Edit ${test.title}`} />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title={`Edit ${test.title}`}
                    description={`${test.classBatch.name} · ${test.classBatch.period}. The class and events of a test cannot be changed.`}
                    breadcrumbs={[
                        { label: 'Military Fitness', href: routes.fitness.index() },
                        { label: test.title, href: routes.fitness.tests.show(test.id) },
                        { label: 'Edit' },
                    ]}
                />
                <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                    <FormSection title="Test Details">
                        <div className="grid gap-5 sm:grid-cols-3">
                            <FormField label="Title" required error={form.errors.title} className="sm:col-span-2">
                                <TextInput name="title" value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} maxLength={150} autoComplete="off" />
                            </FormField>
                            <FormField label="Test date" required error={form.errors.tested_on}>
                                <TextInput type="date" name="tested_on" value={form.data.tested_on} onChange={(event) => form.setData('tested_on', event.target.value)} />
                            </FormField>
                        </div>
                        <FormField label="Notes" error={form.errors.notes} hint="Optional. Up to 500 characters.">
                            <TextArea name="notes" value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} maxLength={500} rows={2} />
                        </FormField>
                    </FormSection>
                    <FormActions>
                        <ButtonLink href={routes.fitness.tests.show(test.id)} variant="secondary">
                            Cancel
                        </ButtonLink>
                        <Button type="submit" loading={form.processing}>
                            Save Changes
                        </Button>
                    </FormActions>
                </form>
            </div>
        </>
    );
}
