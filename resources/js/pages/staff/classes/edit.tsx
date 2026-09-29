import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/button';
import { FormField, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';

interface EditClassProps {
    classBatch: { id: number; name: string; period: string };
}

export default function EditClass({ classBatch }: EditClassProps) {
    const { singular, plural } = terms.classBatch;
    const form = useForm({ name: classBatch.name });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put(routes.classes.update(classBatch.id), { preserveScroll: true });
    };

    return (
        <>
            <Head title={`Edit ${classBatch.name}`} />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title={`Edit ${singular}`}
                    description={`Academic period: ${classBatch.period}`}
                    breadcrumbs={[
                        { label: plural, href: routes.classes.index() },
                        { label: classBatch.name, href: routes.classes.show(classBatch.id) },
                        { label: 'Edit' },
                    ]}
                />

                <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                    <FormSection title={`${singular} Details`}>
                        <FormField label="Name" required error={form.errors.name}>
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
                        <ButtonLink href={routes.classes.show(classBatch.id)} variant="secondary">
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
