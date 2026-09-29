import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AssessmentForm, type AssessmentFormData } from '@/components/grading/assessment-form';
import { gradebookBreadcrumbs, OfferingDescription } from '@/components/grading/offering-context';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';
import type { GradingCategory, OfferingContext, StatusValue } from '@/types/grading';

interface EditAssessmentProps {
    offering: OfferingContext;
    categories: GradingCategory[];
    assessment: {
        id: number;
        title: string;
        category: { id: number; name: string; weight: string };
        maxScore: string;
        assessedOn: string | null;
        status: StatusValue;
        highestScore: string | null;
    };
}

export default function EditAssessment({ offering, categories, assessment }: EditAssessmentProps) {
    const form = useForm<AssessmentFormData>({
        title: assessment.title,
        assessment_category_id: String(assessment.category.id),
        max_score: assessment.maxScore,
        assessed_on: assessment.assessedOn ?? '',
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put(routes.assessments.update(assessment.id), { preserveScroll: true });
    };

    return (
        <>
            <Head title={`Edit ${assessment.title}`} />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title={`Edit ${assessment.title}`}
                    description={
                        <>
                            {offering.subject.name} · <OfferingDescription offering={offering} />
                        </>
                    }
                    breadcrumbs={[
                        ...gradebookBreadcrumbs(offering),
                        { label: assessment.title, href: routes.assessments.show(assessment.id) },
                        { label: 'Edit' },
                    ]}
                />
                <AssessmentForm
                    form={form}
                    categories={categories}
                    submitLabel="Save Changes"
                    cancelHref={routes.assessments.show(assessment.id)}
                    highestScore={assessment.highestScore}
                    onSubmit={submit}
                />
            </div>
        </>
    );
}
