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
    /** share: the area's percentage of the overall score (weight ÷ total of the active weights); null when inactive. */
    areas: Array<PerformanceAreaRow & { subjects: AreaSubject[]; share: number | null }>;
    /** Total weight of the active areas. */
    activeWeightTotal: number;
    hasActiveMustPass: boolean;
    /** Active subjects whose grades count toward no area. */
    unmappedSubjects: Array<{ id: number; code: string; name: string }>;
}

/** "Base 85 · +1 per merit point · −1.5 per demerit point" */
function conductRuleLabel(area: PerformanceAreaRow): string {
    return `Base ${formatNumber(Number(area.baseRating))} · +${formatNumber(Number(area.meritValue))} per merit point · −${formatNumber(Number(area.demeritValue))} per demerit point`;
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
                description="Area passing grades decide qualification only. Subject standing uses Passing and Warning Grades (Grading Setup)."
                breadcrumbs={[{ label: 'Qualification', href: routes.qualification.index() }, { label: 'Performance Areas' }]}
                actions={areas.length > 0 && addAction}
            />

            <div className="flex flex-col gap-6">
                {unmappedSubjects.length > 0 && (
                    <Alert tone="warning" title={`${unmappedSubjects.length} ${unmappedSubjects.length === 1 ? 'subject counts' : 'subjects count'} toward no area`}>
                        {unmappedSubjects.map((subject) => `${subject.name} (${subject.code})`).join(', ')}. Add them to a Subject Grades area.
                    </Alert>
                )}
                {activeCount > 0 && !hasActiveMustPass && (
                    <Alert tone="warning" title="No active must-pass area">
                        Every candidate is Qualified. Mark the required areas as must-pass.
                    </Alert>
                )}

                <section className="rounded-lg border border-line-box bg-surface" aria-label="Performance areas">
                    {areas.length === 0 ? (
                        <EmptyState
                            icon={Award}
                            title="No performance areas yet"
                            description="Add an area to decide qualification."
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
                                                            ? 'No subjects yet'
                                                            : area.subjects.map((subject) => `${subject.name}${subject.isActive ? '' : ' (inactive)'}`).join(', ')}
                                                    </span>
                                                )}
                                                {area.source.value === 'conduct' && <span className="block text-xs text-ink-muted">{conductRuleLabel(area)}</span>}
                                            </Td>
                                            <Td align="right" numeric className="text-ink">
                                                {formatNumber(Number(area.weight))}
                                                {area.share !== null && <span className="block text-xs text-ink-muted">{area.share}% of final course grade</span>}
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
                                Total active weight: <span className="font-semibold text-ink tabular-nums">{formatNumber(activeWeightTotal)}</span>. Weights are
                                relative: final course grade = weighted average of the graded areas.
                            </p>
                        </>
                    )}
                </section>
            </div>
        </>
    );
}
