import { Head, Link } from '@inertiajs/react';
import { ChartFigure } from '@/components/charts/chart-figure';
import { StandingBreakdown } from '@/components/charts/standing-breakdown';
import type { CampusRow } from '@/components/academic/campus-form';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatGrade, formatPercent } from '@/lib/format';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import type { CampusFigures } from '@/types/campus-analytics';

interface CampusListRow extends CampusRow {
    classCount: number;
    candidateCount: number;
    staffCount: number;
    /** Figures of the active academic year. */
    figures: CampusFigures;
}

interface CampusesProps {
    campuses: CampusListRow[];
    period: { id: number; name: string } | null;
}

/** The institution's four fixed campuses (owner decision 2026-10-04): South, North, East and West, compared side by side. */
export default function Campuses({ campuses, period }: CampusesProps) {
    return (
        <>
            <Head title="Campuses" />

            <PageHeader
                title="Campuses"
                description={`${terms.classBatch.plural}, ${terms.candidate.plural.toLowerCase()} and instructors belong to a campus. Academic years, subjects and settings are shared by every campus. The four campuses are fixed: none is added or removed, but a campus can be switched off.`}
            />

            <div className="flex flex-col gap-6">
                <CampusComparison campuses={campuses} period={period} />

                <div className="rounded-lg border border-line-box bg-surface">
                    <Table caption="Campuses" className="min-w-[40rem]">
                        <TableHead>
                            <Th>Campus</Th>
                            <Th align="right">{terms.classBatch.plural}</Th>
                            <Th align="right">{terms.candidate.plural}</Th>
                            <Th align="right">Active staff</Th>
                            <Th>Status</Th>
                            <Th align="right">
                                <span className="sr-only">Actions</span>
                            </Th>
                        </TableHead>
                        <TableBody>
                            {campuses.map((campus) => (
                                <Tr key={campus.id}>
                                    <Td className="text-ink">
                                        <Link href={routes.campuses.show(campus.id)} className="font-medium text-primary-700 underline">
                                            {campus.name}
                                        </Link>
                                        <span className="block text-xs text-ink-muted">
                                            {campus.code}
                                            {campus.address && ` · ${campus.address}`}
                                        </span>
                                    </Td>
                                    <Td align="right" className="tabular-nums">
                                        {campus.classCount}
                                    </Td>
                                    <Td align="right" className="tabular-nums">
                                        {campus.candidateCount}
                                    </Td>
                                    <Td align="right" className="tabular-nums">
                                        {campus.staffCount}
                                    </Td>
                                    <Td>{campus.isActive ? <StatusBadge tone="success">Active</StatusBadge> : <StatusBadge tone="neutral">Inactive</StatusBadge>}</Td>
                                    <Td align="right">
                                        <div className="flex justify-end gap-3">
                                            <RowAction href={routes.campuses.show(campus.id)} label={`Analytics of ${campus.name}`}>
                                                Analytics
                                            </RowAction>
                                            <RowAction href={routes.campuses.edit(campus.id)} label={`Edit ${campus.name}`}>
                                                Edit
                                            </RowAction>
                                        </div>
                                    </Td>
                                </Tr>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </>
    );
}

/** The campuses' standings side by side, then their key figures in one table. */
function CampusComparison({ campuses, period }: CampusesProps) {
    if (period === null) {
        return (
            <Panel title="Campus Comparison" description="Compares the campuses in the active academic year.">
                <p className="text-sm text-ink-muted">There is no active academic year yet, so there is nothing to compare.</p>
            </Panel>
        );
    }

    return (
        <Panel title="Campus Comparison" description={`${period.name} · each campus's ${terms.candidate.plural.toLowerCase()}, standing, qualification, attendance, fitness and examinations.`}>
            <div className="flex flex-col gap-6">
                <ChartFigure title="Academic standing by campus" description="The overall standing of each campus's candidates, from their current subject grades.">
                    <StandingBreakdown
                        groups={campuses.map((campus) => ({ label: campus.name, counts: campus.figures.standing }))}
                        renderLabel={(group, index) => {
                            const campus = campuses[index];

                            return campus === undefined ? (
                                group.label
                            ) : (
                                <Link href={routes.campuses.show(campus.id)} className="text-primary-700 underline">
                                    {group.label}
                                </Link>
                            );
                        }}
                        noun={{ one: terms.candidate.singular.toLowerCase(), other: terms.candidate.plural.toLowerCase() }}
                    />
                </ChartFigure>

                <div className="overflow-x-auto rounded-lg border border-line-box">
                    <Table caption="Key figures by campus" className="min-w-[52rem]">
                        <TableHead>
                            <Th>Campus</Th>
                            <Th align="right">{terms.candidate.plural}</Th>
                            <Th align="right">Qualified</Th>
                            <Th align="right">Attendance</Th>
                            <Th align="right">Fitness passed</Th>
                            <Th align="right">Merits / demerits</Th>
                            <Th align="right">Exam attempts</Th>
                            <Th align="right">Mean exam score</Th>
                        </TableHead>
                        <TableBody>
                            {campuses.map(({ id, name, figures }) => (
                                <Tr key={id}>
                                    <Td className="font-medium text-ink">{name}</Td>
                                    <Td align="right" numeric>
                                        {figures.candidateCount}
                                    </Td>
                                    <Td align="right" numeric>
                                        {figures.qualification?.configured ? `${figures.qualification.counts.qualified} of ${figures.qualification.counts.total}` : '—'}
                                    </Td>
                                    <Td align="right" numeric>
                                        {formatPercent(figures.attendanceRate)}
                                    </Td>
                                    <Td align="right" numeric>
                                        {formatPercent(figures.fitness.passRate)}
                                    </Td>
                                    <Td align="right" numeric>
                                        {figures.conduct.merits} / {figures.conduct.demerits}
                                    </Td>
                                    <Td align="right" numeric>
                                        {figures.examinations.submitted}
                                    </Td>
                                    <Td align="right" numeric>
                                        {figures.examinations.meanScore === null ? '—' : formatGrade(figures.examinations.meanScore)}
                                    </Td>
                                </Tr>
                            ))}
                        </TableBody>
                    </Table>
                </div>
                <p className="text-xs text-ink-muted">
                    Attendance: (present + late) ÷ (present + late + absent). Fitness passed: share of decided results (passed or failed) across the campus&apos;s
                    fitness tests. A dash means no records yet.
                </p>
            </div>
        </Panel>
    );
}
