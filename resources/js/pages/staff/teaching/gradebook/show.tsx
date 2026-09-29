import { Head } from '@inertiajs/react';
import { ClipboardList, GraduationCap, Plus, SearchX } from 'lucide-react';
import { useState } from 'react';
import { GradeBreakdownDialog } from '@/components/grading/grade-breakdown-dialog';
import { gradebookBreadcrumbs, OfferingDescription, WeightSummary } from '@/components/grading/offering-context';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar, SearchField } from '@/components/ui/filter-bar';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { Panel } from '@/components/ui/panel';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatCalendarDate, formatGrade, formatPercent } from '@/lib/format';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { Paginated } from '@/types';
import type { AssessmentSummary, CandidateSummary, GradingCategory, OfferingContext, SubjectGrade } from '@/types/grading';

interface GradeRow {
    candidate: CandidateSummary;
    result: SubjectGrade;
}

interface GradebookProps {
    offering: OfferingContext;
    scheme: GradingCategory[];
    assessments: AssessmentSummary[];
    /** Candidates who can currently be graded in the class. */
    gradableCount: number;
    grades: Paginated<GradeRow>;
    filters: { search: string; [key: string]: string };
    can: { recordGrades: boolean };
}

export default function Gradebook({ offering, scheme, assessments, gradableCount, grades, filters, can }: GradebookProps) {
    const { values, update, reset, isFiltered } = useQueryFilters(routes.teaching.gradebook(offering.classBatch.id, offering.id), filters);
    const [breakdownFor, setBreakdownFor] = useState<GradeRow | null>(null);
    const classTerm = terms.classBatch.singular.toLowerCase();
    const isConfigured = scheme.length > 0;
    const canCreate = can.recordGrades && isConfigured;
    const createHref = routes.teaching.assessments.create(offering.classBatch.id, offering.id);

    return (
        <>
            <Head title={`${offering.subject.name} · ${offering.classBatch.name}`} />

            <PageHeader
                title={offering.subject.name}
                description={<OfferingDescription offering={offering} />}
                breadcrumbs={gradebookBreadcrumbs(offering)}
                actions={
                    canCreate && (
                        <ButtonLink href={createHref} variant="primary" icon={<Plus className="size-4" aria-hidden="true" />}>
                            Create Assessment
                        </ButtonLink>
                    )
                }
            />

            <div className="flex flex-col gap-6">
                {isConfigured ? (
                    <Panel title="Grading Components" description="Set by academic administrators. The weights add up to 100%.">
                        <WeightSummary categories={scheme} />
                    </Panel>
                ) : (
                    <Alert tone="warning" title="Grading is not set up for this subject">
                        An academic administrator must set the grading categories and weights for {offering.subject.name} in{' '}
                        {offering.classBatch.name} before assessments can be created.
                    </Alert>
                )}

                <Panel
                    title="Assessments"
                    description="Only finalized assessments count toward grades."
                    bodyClassName="p-0"
                >
                    {assessments.length === 0 ? (
                        <EmptyState
                            icon={ClipboardList}
                            headingLevel="h3"
                            title="No assessments yet"
                            description={
                                canCreate
                                    ? "Create an assessment for this subject when you're ready, then record the scores."
                                    : 'Assessments for this subject will be listed here.'
                            }
                            action={
                                canCreate && (
                                    <ButtonLink href={createHref} variant="secondary" icon={<Plus className="size-4" aria-hidden="true" />}>
                                        Create Assessment
                                    </ButtonLink>
                                )
                            }
                        />
                    ) : (
                        <Table caption={`Assessments of ${offering.subject.name}`} className="min-w-[44rem]">
                            <TableHead>
                                <Th>Title</Th>
                                <Th>Category</Th>
                                <Th>Date</Th>
                                <Th align="right">Max Score</Th>
                                <Th align="right">Scores Recorded</Th>
                                <Th>Status</Th>
                                <Th align="right">
                                    <span className="sr-only">Actions</span>
                                </Th>
                            </TableHead>
                            <TableBody>
                                {assessments.map((assessment) => (
                                    <Tr key={assessment.id}>
                                        <Td className="font-medium text-ink">{assessment.title}</Td>
                                        <Td className="text-ink">{assessment.category.name}</Td>
                                        <Td className="whitespace-nowrap text-ink">{assessment.assessedOn === null ? '—' : formatCalendarDate(assessment.assessedOn)}</Td>
                                        <Td align="right" numeric>
                                            {assessment.maxScore}
                                        </Td>
                                        <Td align="right" numeric>
                                            {assessment.scoredCount} of {gradableCount}
                                        </Td>
                                        <Td>
                                            <StatusBadge tone={assessment.status.tone}>{assessment.status.label}</StatusBadge>
                                        </Td>
                                        <Td align="right">
                                            {can.recordGrades && assessment.status.value === 'draft' ? (
                                                <RowAction href={routes.assessments.show(assessment.id)} label={`Record Scores for ${assessment.title}`}>
                                                    Record Scores
                                                </RowAction>
                                            ) : (
                                                <RowAction href={routes.assessments.show(assessment.id)} label={`Open ${assessment.title}`}>
                                                    Open
                                                </RowAction>
                                            )}
                                        </Td>
                                    </Tr>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Panel>

                <Panel
                    title="Candidate Grades"
                    description={
                        <>
                            Calculated from finalized assessments.{' '}
                            <span className="tabular-nums">{gradableCount}</span> {gradableCount === 1 ? 'candidate' : 'candidates'} in this{' '}
                            {classTerm}.
                        </>
                    }
                    bodyClassName="p-0"
                >
                    <FilterBar onReset={reset} canReset={isFiltered}>
                        <SearchField
                            placeholder="Search candidates by number or name…"
                            value={values.search}
                            onChange={(value) => update('search', value, { debounce: true })}
                        />
                    </FilterBar>

                    {grades.data.length === 0 ? (
                        isFiltered ? (
                            <EmptyState
                                icon={SearchX}
                                headingLevel="h3"
                                title="No candidates match this search"
                                description="Try a different number or name, or reset the filters."
                                action={
                                    <Button variant="secondary" onClick={reset}>
                                        Reset Filters
                                    </Button>
                                }
                            />
                        ) : (
                            <EmptyState
                                icon={GraduationCap}
                                headingLevel="h3"
                                title={`No candidates in this ${classTerm} yet`}
                                description={`Candidates appear here once an administrator assigns them to this ${classTerm}.`}
                            />
                        )
                    ) : (
                        <Table caption={`Grades in ${offering.subject.name}`} className="min-w-[40rem]">
                            <TableHead>
                                <Th>Candidate No.</Th>
                                <Th>Name</Th>
                                {scheme.map((category) => (
                                    <Th key={category.id} align="right" className="hidden xl:table-cell">
                                        {category.name} <span className="font-normal normal-case tracking-normal">({category.weight}%)</span>
                                    </Th>
                                ))}
                                <Th align="right">Current Grade</Th>
                                <Th>Status</Th>
                                <Th align="right">
                                    <span className="sr-only">Actions</span>
                                </Th>
                            </TableHead>
                            <TableBody>
                                {grades.data.map((row) => (
                                    <Tr key={row.candidate.id}>
                                        <Td className="font-medium text-ink" numeric>
                                            {row.candidate.candidateNumber}
                                        </Td>
                                        <Td className="text-ink">{row.candidate.name}</Td>
                                        {row.result.categories.map((category) => (
                                            <Td key={category.categoryId} align="right" numeric className="hidden text-ink xl:table-cell">
                                                {formatPercent(category.percentage)}
                                            </Td>
                                        ))}
                                        <Td align="right" numeric className="font-semibold text-ink">
                                            {formatGrade(row.result.grade)}
                                        </Td>
                                        <Td>
                                            <StatusBadge tone={row.result.status.tone}>
                                                {row.result.status.label}
                                                {row.result.missingScores > 0 && ` (${row.result.missingScores})`}
                                            </StatusBadge>
                                        </Td>
                                        <Td align="right">
                                            <div className="flex justify-end gap-1">
                                                {isConfigured && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        aria-label={`Grade breakdown for ${row.candidate.name}`}
                                                        onClick={() => setBreakdownFor(row)}
                                                    >
                                                        Breakdown
                                                    </Button>
                                                )}
                                                <RowAction href={routes.candidates.show(row.candidate.id)} label={`View profile of ${row.candidate.name}`}>
                                                    Profile
                                                </RowAction>
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </TableBody>
                        </Table>
                    )}

                    <Pagination page={grades} noun={{ one: 'candidate', other: 'candidates' }} />
                </Panel>
            </div>

            <GradeBreakdownDialog subjectName={offering.subject.name} entry={breakdownFor} onClose={() => setBreakdownFor(null)} />
        </>
    );
}
