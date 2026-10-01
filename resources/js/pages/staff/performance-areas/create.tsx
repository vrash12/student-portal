import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { PerformanceAreaForm, areaPayload, type PerformanceAreaFormData } from '@/components/performance/performance-area-form';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';
import type { ActiveSingleSourceAreas, AreaSubjectOption, PerformanceSourceOption } from '@/types/performance';

interface CreatePerformanceAreaProps {
    sources: PerformanceSourceOption[];
    subjects: AreaSubjectOption[];
    activeSingleSourceAreas: ActiveSingleSourceAreas;
    nextSortOrder: number;
}

export default function CreatePerformanceArea({ sources, subjects, activeSingleSourceAreas, nextSortOrder }: CreatePerformanceAreaProps) {
    const form = useForm<PerformanceAreaFormData>({
        name: '',
        description: '',
        source: 'subjects',
        weight: '',
        passing_grade: '',
        must_pass: true,
        base_rating: '',
        merit_value: '',
        demerit_value: '',
        sort_order: String(nextSortOrder),
        is_active: true,
        subject_ids: [],
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.transform(areaPayload);
        form.post(routes.performanceAreas.store(), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Add Performance Area" />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title="Add Performance Area"
                    description="An area candidates are assessed in, with its weight in the overall score and its passing grade."
                    breadcrumbs={[
                        { label: 'Qualification', href: routes.qualification.index() },
                        { label: 'Performance Areas', href: routes.performanceAreas.index() },
                        { label: 'Add Area' },
                    ]}
                />
                <PerformanceAreaForm
                    form={form}
                    areaId={null}
                    sources={sources}
                    subjects={subjects}
                    activeSingleSourceAreas={activeSingleSourceAreas}
                    submitLabel="Add Area"
                    onSubmit={submit}
                />
            </div>
        </>
    );
}
