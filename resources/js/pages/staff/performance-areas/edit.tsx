import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { PerformanceAreaForm, areaPayload, type PerformanceAreaFormData } from '@/components/performance/performance-area-form';
import { Alert } from '@/components/ui/alert';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';
import type { ActiveSingleSourceAreas, AreaSubjectOption, PerformanceAreaRow, PerformanceSourceOption } from '@/types/performance';

interface EditPerformanceAreaProps {
    area: PerformanceAreaRow & { subjectIds: number[] };
    sources: PerformanceSourceOption[];
    subjects: AreaSubjectOption[];
    /** Other active areas of the single-area sources (this area excluded). */
    activeSingleSourceAreas: ActiveSingleSourceAreas;
}

export default function EditPerformanceArea({ area, sources, subjects, activeSingleSourceAreas }: EditPerformanceAreaProps) {
    const form = useForm<PerformanceAreaFormData>({
        name: area.name,
        description: area.description ?? '',
        source: area.source.value,
        weight: area.weight,
        passing_grade: area.passingGrade,
        must_pass: area.mustPass,
        base_rating: area.baseRating ?? '',
        merit_value: area.meritValue ?? '',
        demerit_value: area.demeritValue ?? '',
        sort_order: String(area.sortOrder),
        is_active: area.isActive,
        subject_ids: area.subjectIds,
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.transform(areaPayload);
        form.put(routes.performanceAreas.update(area.id), { preserveScroll: true });
    };

    const leavesSubjects = area.source.value === 'subjects' && form.data.source !== 'subjects' && area.subjectIds.length > 0;

    return (
        <>
            <Head title={`Edit ${area.name}`} />

            <div className="mx-auto flex max-w-3xl flex-col gap-6">
                <PageHeader
                    title={area.name}
                    description={`${area.source.label} · ${area.isActive ? 'active' : 'inactive'}`}
                    breadcrumbs={[
                        { label: 'Qualification', href: routes.qualification.index() },
                        { label: 'Performance Areas', href: routes.performanceAreas.index() },
                        { label: area.name },
                    ]}
                />
                <Alert title="Changes apply immediately">Results, qualification and ranks update for every candidate.</Alert>
                {leavesSubjects && (
                    <Alert tone="warning" title="Subjects will be removed from this area">
                        Its {area.subjectIds.length} {area.subjectIds.length === 1 ? 'subject' : 'subjects'} will count toward no area.
                    </Alert>
                )}
                <PerformanceAreaForm
                    form={form}
                    areaId={area.id}
                    sources={sources}
                    subjects={subjects}
                    activeSingleSourceAreas={activeSingleSourceAreas}
                    submitLabel="Save Changes"
                    onSubmit={submit}
                />
            </div>
        </>
    );
}
