import { Head } from '@inertiajs/react';
import { Award, Plus } from 'lucide-react';
import { formatNumber } from '@/components/performance/area-status';
import { Alert } from '@/components/ui/alert';
import { ButtonLink } from '@/components/ui/button';
import { ClientPagination, useClientPagination } from '@/components/ui/client-pagination';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { routes } from '@/lib/routes';
import type { PerformanceAreaRow } from '@/types/performance';

interface AreaSubject {
    id: number;
    code: string;
    name: string;
    isActive: boolean;
}

interface PerformanceAreasProps {
    areas: Array<PerformanceAreaRow & { subjects: AreaSubject[] }>;
    /** Total weight of the active areas. */
    activeWeightTotal: number;
    hasActiveMustPass: boolean;
    /** Active subjects whose grades count toward no area. */
    unmappedSubjects: Array<{ id: number; code: string; name: string }>;
}

/** "Base 85 · merit point +1 · demerit point −1.5" */
function conductRuleLabel(area: PerformanceAreaRow): string {
    return `Base ${formatNumber(Number(area.baseRating))} · merit point +${formatNumber(Number(area.meritValue))} · demerit point −${formatNumber(Number(area.demeritValue))}`;
}

export default function PerformanceAreas({ areas, activeWeightTotal, hasActiveMustPass, unmappedSubjects }: PerformanceAreasProps) {
    const activeCount = areas.filter((area) => area.isActive).length;
    const pagination = useClientPagination(areas);
    const addAction = (
        <ButtonLink href={routes.performanceAreas.create()} variant="primary" icon={<Plus className="size-4" aria-hidden="true" />}>
            Add Area
        </ButtonLink>
    );

    return (
        <>
            <Head title="Performance Areas" />

            <PageHeader
                title="Performance Areas"
                description="The areas candidates are assessed in, their weight in the overall score, their passing grades and which must be passed to qualify. The values are placeholders until the institution confirms its official grading rules. Areas are deactivated, never deleted."
                breadcrumbs={[{ label: 'Qualification', href: routes.qualification.index() }, { label: 'Performance Areas' }]}
                actions={addAction}
            />

            <div className="flex flex-col gap-6">
                {unmappedSubjects.length > 0 && (
                    <Alert tone="warning" title={`${unmappedSubjects.length} ${unmappedSubjects.length === 1 ? 'subject counts' : 'subjects count'} toward no area`}>
                        Their grades are not part of any area or of qualification:{' '}
                        {unmappedSubjects.map((subject) => `${subject.name} (${subject.code})`).join(', ')}. Add them to an area based on subject grades.
                    </Alert>
                )}
                {activeCount > 0 && !hasActiveMustPass && (
                    <Alert tone="warning" title="No active area must be passed">
                        Qualification only looks at must-pass areas, so every candidate is reported Qualified. Mark the required areas as must pass.
                    </Alert>
                )}

                <section className="rounded-lg border border-line-box bg-surface" aria-label="Performance areas">
                    {areas.length === 0 ? (
                        <EmptyState
                            icon={Award}
                            title="No performance areas yet"
                            description="Add the areas candidates must be assessed in, such as academic subjects, military fitness, conduct and attendance. Until then, qualification cannot be decided."
                            action={addAction}
                        />
                    ) : (
                        <>
                            <Table caption="Performance areas" className="min-w-[60rem]">
                                <TableHead>
                                    <Th align="right">Order</Th>
                                    <Th>Area</Th>
                                    <Th>Grade Taken From</Th>
                                    <Th align="right">Weight</Th>
                                    <Th align="right">Passing Grade</Th>
                                    <Th>Must Pass</Th>
                                    <Th>Status</Th>
                                    <Th align="right">
                                        <span className="sr-only">Actions</span>
                                    </Th>
                                </TableHead>
                                <TableBody>
                                    {pagination.rows.map((area) => (
                                        <Tr key={area.id}>
                                            <Td align="right" numeric>
                                                {area.sortOrder}
                                            </Td>
                                            <Td className="text-ink">
                                                <span className="font-medium">{area.name}</span>
                                                {area.description && <span className="block text-xs text-ink-muted">{area.description}</span>}
                                            </Td>
                                            <Td className="text-ink">
                                                {area.source.label}
                                                {area.source.value === 'subjects' && (
                                                    <span className="block text-xs text-ink-muted">
                                                        {area.subjects.length === 0
                                                            ? 'No subjects mapped yet'
                                                            : area.subjects.map((subject) => `${subject.name}${subject.isActive ? '' : ' (inactive)'}`).join(', ')}
                                                    </span>
                                                )}
                                                {area.source.value === 'conduct' && <span className="block text-xs text-ink-muted">{conductRuleLabel(area)}</span>}
                                            </Td>
                                            <Td align="right" numeric className="text-ink">
                                                {formatNumber(Number(area.weight))}
                                            </Td>
                                            <Td align="right" numeric className="text-ink">
                                                {formatNumber(Number(area.passingGrade))}
                                            </Td>
                                            <Td className="text-ink">{area.mustPass ? 'Yes' : 'No'}</Td>
                                            <Td>
                                                {area.isActive ? <StatusBadge tone="success">Active</StatusBadge> : <StatusBadge tone="neutral">Inactive</StatusBadge>}
                                            </Td>
                                            <Td align="right">
                                                <RowAction href={routes.performanceAreas.edit(area.id)} label={`Edit ${area.name}`}>
                                                    Edit
                                                </RowAction>
                                            </Td>
                                        </Tr>
                                    ))}
                                </TableBody>
                            </Table>
                            <ClientPagination pagination={pagination} noun={{ one: 'area', other: 'areas' }} label="Performance area pages" />
                            <p className="border-t border-line px-4 py-3 text-sm text-ink-muted">
                                Total weight of the active areas: <span className="font-semibold text-ink tabular-nums">{formatNumber(activeWeightTotal)}</span>.
                                Weights are relative: the overall score is the weighted mean of the area grades, divided by the total weight of the areas that
                                have a grade.
                            </p>
                        </>
                    )}
                </section>
            </div>
        </>
    );
}
