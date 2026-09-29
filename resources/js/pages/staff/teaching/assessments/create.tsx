import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AssessmentForm, type AssessmentFormData } from '@/components/grading/assessment-form';
import { gradebookBreadcrumbs, OfferingDescription } from '@/components/grading/offering-context';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';
import type { GradingCategory, OfferingContext } from '@/types/grading';

interface CreateAssessmentProps {
    offering: OfferingContext;
    categories: GradingCategory[];
}

export default function CreateAssessment({ offering, categories }: CreateAssessmentProps) {
    const form = useForm<AssessmentFormData>({
        title: '',
        // A single category is the only possible choice.
        assessment_category_id: categories.length === 1 ? String(categories[0]?.id) : '',
        max_score: '',
        assessed_on: '',
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(routes.teaching.assessments.store(offering.classBatch.id, offering.id), { preserveScroll: true });
    };

    return (
        <>
            <Head title={`Create Assessment · ${offering.subject.name}`} />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title="Create Assessment"
                    description={
                        <>
                            {offering.subject.name} · <OfferingDescription offering={offering} />
                        </>
                    }
                    breadcrumbs={gradebookBreadcrumbs(offering, 'Create Assessment')}
                />
                <AssessmentForm
                    form={form}
                    categories={categories}
                    submitLabel="Create Assessment"
                    cancelHref={routes.teaching.gradebook(offering.classBatch.id, offering.id)}
                    onSubmit={submit}
                />
            </div>
        </>
    );
}
