import { Head, Link } from '@inertiajs/react';
import { Pencil, Plus, SlidersHorizontal, Users } from 'lucide-react';
import { Alert } from '@/components/ui/alert';
import { ButtonLink } from '@/components/ui/button';
import { ClientPagination, useClientPagination } from '@/components/ui/client-pagination';
import { EmptyState } from '@/components/ui/empty-state';
import { MetricCard } from '@/components/ui/metric-card';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatCalendarDate, formatGrade } from '@/lib/format';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import type { GradingThresholds } from '@/types/grading';

interface PeriodInstructor {
    id: number;
    name: string;
    isActive: boolean;
}

interface PeriodSubject {
    classSubjectId: number;
    code: string;
    name: string;
    isActive: boolean;
    instructors: PeriodInstructor[];
}

interface PeriodClass {
    id: number;
    name: string;
    candidateCount: number;
    subjects: PeriodSubject[];
}

interface AcademicPeriodShowProps {
    period: {
        id: number;
        name: string;
        startsOn: string;
        endsOn: string;
        isActive: boolean;
        thresholds: GradingThresholds | null;
    };
    classes: PeriodClass[];
    totals: { classes: number; subjects: number; instructors: number; candidates: number; unassignedSubjects: number };
    can: { configureGrading: boolean; manageClasses: boolean; manageAssignments: boolean };
}

/** One academic period with everything that hangs off it: classes, their subjects, and the assigned instructors. */
export default function AcademicPeriodShow({ period, classes, totals, can }: AcademicPeriodShowProps) {
    const { singular, plural } = terms.classBatch;

    return (
        <>
            <Head title={period.name} />

            <PageHeader
                title={period.name}
                description={
                    <>
                        {formatCalendarDate(period.startsOn)} – {formatCalendarDate(period.endsOn)}{' '}
                        {period.isActive ? <StatusBadge tone="success">Active period</StatusBadge> : <StatusBadge tone="neutral">Inactive period</StatusBadge>}
                    </>
                }
                breadcrumbs={[{ label: 'Academic Periods', href: routes.academicPeriods.index() }, { label: period.name }]}
                actions={
                    <>
                        {can.configureGrading && (
                            <ButtonLink href={routes.academicPeriods.thresholds(period.id)} icon={<SlidersHorizontal className="size-4" aria-hidden="true" />}>
                                Thresholds
                            </ButtonLink>
                        )}
                        <ButtonLink href={routes.academicPeriods.edit(period.id)} icon={<Pencil className="size-4" aria-hidden="true" />}>
                            Edit Period
                        </ButtonLink>
                    </>
                }
            />

            <div className="flex flex-col gap-6">
                <dl className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <MetricCard label={plural} value={totals.classes} />
                    <MetricCard label="Subjects" value={totals.subjects} />
                    <MetricCard label="Instructors" value={totals.instructors} />
                    <MetricCard label="Candidates" value={totals.candidates} />
                </dl>

                <p className="text-sm text-ink-muted">
                    Grading thresholds:{' '}
                    {period.thresholds === null ? (
                        <StatusBadge tone="neutral">Not Set</StatusBadge>
                    ) : (
                        <span className="text-ink">
                            Passing <span className="tabular-nums">{formatGrade(period.thresholds.passingGrade)}</span> · Warning{' '}
                            <span className="tabular-nums">{formatGrade(period.thresholds.warningGrade)}</span>
                        </span>
                    )}
                </p>

                {totals.unassignedSubjects > 0 && (
                    <Alert tone="warning" title="Subjects without an instructor">
                        {totals.unassignedSubjects === 1 ? '1 subject has' : `${totals.unassignedSubjects} subjects have`} no instructor assigned in this period.
                        Open the {singular.toLowerCase()} to assign one.
                    </Alert>
                )}

                {classes.length === 0 ? (
                    <div className="rounded-lg border border-line bg-surface">
                        <EmptyState
                            icon={Users}
                            title={`No ${plural.toLowerCase()} in this period`}
                            description={`Create a ${singular.toLowerCase()} for ${period.name}, then add its subjects and assign instructors.`}
                            action={
                                can.manageClasses ? (
                                    <ButtonLink href={routes.classes.create()} variant="primary" icon={<Plus className="size-4" aria-hidden="true" />}>
                                        Create {singular}
                                    </ButtonLink>
                                ) : undefined
                            }
                        />
                    </div>
                ) : (
                    classes.map((classBatch) => <ClassSubjectsPanel key={classBatch.id} classBatch={classBatch} can={can} />)
                )}
            </div>
        </>
    );
}

/** One class with its subjects and assigned instructors, paged so long subject lists stay readable. */
function ClassSubjectsPanel({ classBatch, can }: { classBatch: PeriodClass; can: AcademicPeriodShowProps['can'] }) {
    const { singular } = terms.classBatch;
    const pagination = useClientPagination(classBatch.subjects);

    return (
        <Panel
            title={classBatch.name}
            description={`${classBatch.candidateCount} ${classBatch.candidateCount === 1 ? 'candidate' : 'candidates'} · ${classBatch.subjects.length} ${classBatch.subjects.length === 1 ? 'subject' : 'subjects'}`}
            actions={
                can.manageClasses ? (
                    <ButtonLink href={routes.classes.show(classBatch.id)} size="sm">
                        Manage {singular}
                    </ButtonLink>
                ) : undefined
            }
            bodyClassName="p-0"
        >
            {classBatch.subjects.length === 0 ? (
                <p className="px-4 py-4 text-sm text-ink-muted">No subjects added to this {singular.toLowerCase()} yet.</p>
            ) : (
                <Table caption={`Subjects and instructors of ${classBatch.name}`} className="min-w-[32rem]">
                    <TableHead>
                        <Th>Code</Th>
                        <Th>Subject</Th>
                        <Th>Instructors</Th>
                    </TableHead>
                    <TableBody>
                        {pagination.rows.map((subject) => (
                            <Tr key={subject.classSubjectId}>
                                <Td className="font-medium text-ink">{subject.code}</Td>
                                <Td>
                                    {subject.name}{' '}
                                    {!subject.isActive && <StatusBadge tone="neutral">Inactive</StatusBadge>}
                                </Td>
                                <Td>
                                    {subject.instructors.length === 0 ? (
                                        <StatusBadge tone="warning">No instructor assigned</StatusBadge>
                                    ) : (
                                        <ul className="flex flex-wrap gap-x-3 gap-y-1">
                                            {subject.instructors.map((instructor) => (
                                                <li key={instructor.id}>
                                                    {can.manageAssignments ? (
                                                        <Link href={routes.instructors.show(instructor.id)} className="text-primary-700 underline">
                                                            {instructor.name}
                                                        </Link>
                                                    ) : (
                                                        instructor.name
                                                    )}
                                                    {!instructor.isActive && (
                                                        <>
                                                            {' '}
                                                            <StatusBadge tone="neutral">Inactive</StatusBadge>
                                                        </>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </Td>
                            </Tr>
                        ))}
                    </TableBody>
                </Table>
            )}
            <ClientPagination pagination={pagination} noun={{ one: 'subject', other: 'subjects' }} label={`Subject pages of ${classBatch.name}`} />
        </Panel>
    );
}
