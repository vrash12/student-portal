import { Head, Link, router } from '@inertiajs/react';
import { Activity, CalendarClock, SearchX } from 'lucide-react';
import { GradeStatusBadge, StandingBadge, StandingCell, ThresholdSummary } from '@/components/grading/standing';
import { StandingCounts } from '@/components/monitoring/standing-counts';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar, SearchField } from '@/components/ui/filter-bar';
import { FormField, SelectInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { Panel } from '@/components/ui/panel';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatGrade } from '@/lib/format';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { Paginated } from '@/types';
import type { GradingThresholds } from '@/types/grading';
import type { MonitoredCandidateRow, MonitoringScopeKind, StandingCounts as Counts, SubjectAttention, SubjectConcern } from '@/types/monitoring';

interface MonitoringFilters {
    period: string;
    class: string;
    subject: string;
    standing: string;
    search: string;
    sort: string;
    [key: string]: string;
}

interface AcademicMonitoringProps {
    period: { id: number; name: string; isActive: boolean } | null;
    periods: Array<{ id: number; name: string; isActive: boolean }>;
    scope: MonitoringScopeKind;
    thresholds: GradingThresholds | null;
    canConfigureThresholds: boolean;
    counts: Counts;
    subjectsRequiringAttention: SubjectAttention[];
    filters: MonitoringFilters;
    classOptions: Array<{ id: number; name: string }>;
    subjectOptions: Array<{ id: number; code: string; name: string }>;
    standingOptions: Array<{ value: string; label: string }>;
    /** "subject": one subject is selected and every number is that subject's. */
    view: 'overall' | 'subject';
    /** Only one class is shown, so the class column is left out. */
    singleClass: boolean;
    candidates: Paginated<MonitoredCandidateRow>;
}

export default function AcademicMonitoring(props: AcademicMonitoringProps) {
    const { period, periods, scope, thresholds, canConfigureThresholds, counts, subjectsRequiringAttention, filters, view, singleClass, candidates } = props;
    const { values, update, updateMany } = useQueryFilters(routes.monitoring.index(), filters);
    const classTerm = terms.classBatch.singular;
    const selectedSubject = props.subjectOptions.find((subject) => String(subject.id) === filters.subject) ?? null;
    const hasStandings = thresholds !== null && counts.noStanding < counts.monitored;
    // Sort is a view choice, not a filter: it does not enable Reset or the "no matches" message.
    const isFiltered = ['class', 'subject', 'standing', 'search'].some((key) => values[key] !== '');
    const standingContext =
        selectedSubject !== null ? `Standing in ${selectedSubject.name}` : scope === 'taught' ? 'Standing in Your Subjects' : 'Overall Standing';

    // The current filters with some values replaced, for links.
    const queryFor = (patch: Partial<MonitoringFilters>): Record<string, string> =>
        Object.fromEntries(Object.entries({ ...filters, ...patch }).map(([key, value]) => [key, value ?? '']));
    // A new period starts clean: classes and subjects belong to the period.
    const changePeriod = (value: string) => router.get(routes.monitoring.index(), value === '' ? {} : { period: value });
    const resetFilters = () => updateMany({ class: '', subject: '', standing: '', search: '' });

    return (
        <>
            <Head title="Academic Monitoring" />

            <PageHeader
                title="Academic Monitoring"
                description={
                    scope === 'taught'
                        ? 'Candidates in the classes you teach. Standings include only the subjects you teach.'
                        : 'Candidates who need academic attention. A candidate’s overall standing is their most serious subject standing.'
                }
            />

            {period === null ? (
                <div className="rounded-lg border border-line bg-surface">
                    <EmptyState
                        icon={CalendarClock}
                        title="Nothing to monitor yet"
                        description={
                            scope === 'taught'
                                ? 'You are not assigned to teach any subjects. Candidates appear here once an academic administrator assigns you to a class.'
                                : 'Create an academic period with classes and candidates to monitor academic standing.'
                        }
                    />
                </div>
            ) : (
                <div className="flex flex-col gap-6">
                    <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                        <FormField label="Academic Period" className="md:w-72">
                            <SelectInput value={filters.period || String(period.id)} onChange={(event) => changePeriod(event.target.value)}>
                                {periods.map((option) => (
                                    <option key={option.id} value={String(option.id)}>
                                        {option.name}
                                        {option.isActive ? ' (active)' : ''}
                                    </option>
                                ))}
                            </SelectInput>
                        </FormField>
                        {thresholds !== null && (
                            <p className="text-sm text-ink-muted md:text-right">
                                Standing uses the <ThresholdSummary thresholds={thresholds} /> of {period.name}.
                            </p>
                        )}
                    </div>

                    {!period.isActive && (
                        <p className="text-sm text-ink-muted">
                            {period.name} is not the active period. This view shows candidates currently assigned to its {terms.classBatch.plural.toLowerCase()};
                            it is not a historical record.
                        </p>
                    )}

                    {thresholds === null ? (
                        <Alert title="Academic standing is not available for this period">
                            Passing and warning grades have not been set for {period.name}, so candidates are listed with their grades but no
                            standing.
                            {canConfigureThresholds && (
                                <>
                                    {' '}
                                    <Link href={routes.academicPeriods.thresholds(period.id)} className="font-medium text-primary-700 underline">
                                        Set passing and warning grades
                                    </Link>
                                </>
                            )}
                        </Alert>
                    ) : (
                        <section aria-labelledby="standing-counts-heading" className="flex flex-col gap-3">
                            <h2 id="standing-counts-heading" className="text-base font-semibold text-ink">
                                {selectedSubject === null ? 'Candidates by Standing' : `Candidates by Standing in ${selectedSubject.name}`}
                            </h2>
                            {hasStandings ? (
                                <StandingCounts
                                    counts={counts}
                                    totalLabel="Monitored Candidates"
                                    standings={['failing', 'at_risk', 'incomplete', 'passing']}
                                    hrefFor={(standing) => routes.monitoring.index(queryFor({ standing, search: '' }))}
                                    current={filters.standing}
                                />
                            ) : (
                                <p className="rounded-lg border border-line bg-surface px-4 py-3 text-sm text-ink">
                                    No standings yet for the <span className="tabular-nums">{counts.monitored}</span> monitored{' '}
                                    {counts.monitored === 1 ? 'candidate' : 'candidates'}: standings appear once assessments are finalized.
                                </p>
                            )}
                            <p className="text-sm text-ink-muted">
                                Withdrawn candidates are not monitored. Counts include every monitored candidate here, whatever the search or
                                standing filter below.
                            </p>
                        </section>
                    )}

                    {view === 'overall' && hasStandings && (
                        <SubjectsRequiringAttention subjects={subjectsRequiringAttention} hrefFor={(row) => routes.monitoring.index(queryFor({ class: String(row.classBatch.id), subject: String(row.subject.id), standing: '' }))} />
                    )}

                    <Panel title="Candidates" description={selectedSubject === null ? undefined : `Standing in ${selectedSubject.name} only.`} bodyClassName="p-0">
                        <FilterBar onReset={resetFilters} canReset={isFiltered}>
                            <SearchField
                                placeholder="Search candidates by number or name…"
                                value={values.search}
                                onChange={(value) => update('search', value, { debounce: true })}
                            />
                            <FormField label={classTerm} className="sm:w-48">
                                <SelectInput
                                    value={values.class}
                                    // Subjects depend on the class, so a class change clears the subject.
                                    onChange={(event) => updateMany({ class: event.target.value, subject: '' })}
                                >
                                    <option value="">All {terms.classBatch.plural.toLowerCase()}</option>
                                    {props.classOptions.map((option) => (
                                        <option key={option.id} value={String(option.id)}>
                                            {option.name}
                                        </option>
                                    ))}
                                </SelectInput>
                            </FormField>
                            <FormField label="Subject" className="sm:w-48">
                                <SelectInput value={values.subject} onChange={(event) => update('subject', event.target.value)}>
                                    <option value="">All subjects</option>
                                    {props.subjectOptions.map((option) => (
                                        <option key={option.id} value={String(option.id)}>
                                            {option.name}
                                        </option>
                                    ))}
                                </SelectInput>
                            </FormField>
                            {thresholds !== null && (
                                <FormField label="Standing" className="sm:w-44">
                                    <SelectInput value={values.standing} onChange={(event) => update('standing', event.target.value)}>
                                        <option value="">All standings</option>
                                        {props.standingOptions.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </SelectInput>
                                </FormField>
                            )}
                            <FormField label="Sort By" className="sm:w-56">
                                <SelectInput value={values.sort} onChange={(event) => update('sort', event.target.value)}>
                                    {sortOptions(view, thresholds !== null).map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </SelectInput>
                            </FormField>
                        </FilterBar>

                        {candidates.data.length === 0 ? (
                            isFiltered && counts.monitored > 0 ? (
                                <EmptyState
                                    icon={SearchX}
                                    headingLevel="h3"
                                    title="No candidates match these filters"
                                    description="Try a different search or standing, or reset the filters."
                                    action={
                                        <Button variant="secondary" onClick={resetFilters}>
                                            Reset Filters
                                        </Button>
                                    }
                                />
                            ) : (
                                <EmptyState
                                    icon={Activity}
                                    headingLevel="h3"
                                    title="No candidates to monitor"
                                    description={`Candidates appear here once they are assigned to a ${classTerm.toLowerCase()} of ${period.name}.`}
                                />
                            )
                        ) : view === 'subject' ? (
                            <SubjectTable
                                candidates={candidates.data}
                                subjectName={selectedSubject?.name ?? 'Subject'}
                                singleClass={singleClass}
                                hasThresholds={thresholds !== null}
                            />
                        ) : (
                            <OverallTable candidates={candidates.data} standingLabel={standingContext} singleClass={singleClass} hasThresholds={thresholds !== null} />
                        )}

                        <Pagination page={candidates} noun={{ one: 'candidate', other: 'candidates' }} />
                    </Panel>
                </div>
            )}
        </>
    );
}

function sortOptions(view: 'overall' | 'subject', hasThresholds: boolean): Array<{ value: string; label: string }> {
    const grade = view === 'subject' ? 'Grade' : 'Lowest subject grade';

    return [
        ...(hasThresholds ? [{ value: '', label: 'Most serious first' }] : []),
        { value: hasThresholds ? 'lowest' : '', label: `${grade}, low to high` },
        { value: 'highest', label: `${grade}, high to low` },
        { value: 'name', label: 'Name' },
    ];
}

function SubjectsRequiringAttention({ subjects, hrefFor }: { subjects: SubjectAttention[]; hrefFor: (row: SubjectAttention) => string }) {
    return (
        <Panel
            title="Subjects Requiring Attention"
            description="Subjects with failing or at-risk candidates, most failing first."
            bodyClassName={subjects.length === 0 ? undefined : 'p-0'}
        >
            {subjects.length === 0 ? (
                <p className="text-sm text-ink-muted">No subject has failing or at-risk candidates.</p>
            ) : (
                <ul className="divide-y divide-line">
                    {subjects.map((row) => (
                        <li key={row.classSubjectId} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                            <p className="text-sm text-ink">
                                <span className="font-medium">{row.subject.name}</span>, {row.classBatch.name}:{' '}
                                <span className="tabular-nums">{attentionSummary(row)}</span>
                            </p>
                            <RowAction href={hrefFor(row)} label={`View candidates in ${row.subject.name}, ${row.classBatch.name}`}>
                                View candidates
                            </RowAction>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

/** "2 failing, 1 at risk, 1 incomplete", leaving out zero counts (UI_UX_DESIGN.md §98). */
function attentionSummary(row: SubjectAttention): string {
    return [
        [row.failing, 'failing'],
        [row.atRisk, 'at risk'],
        [row.incomplete, 'incomplete'],
    ]
        .filter(([count]) => count !== 0)
        .map(([count, label]) => `${count} ${label}`)
        .join(', ');
}

function CandidateCell({ row }: { row: MonitoredCandidateRow }) {
    return (
        <span className="flex flex-col">
            <span className="text-ink">{row.candidate.name}</span>
            {row.candidate.status !== null && <span className="text-xs text-ink-muted">{row.candidate.status}</span>}
        </span>
    );
}

function ConcernList({ concerns }: { concerns: SubjectConcern[] }) {
    if (concerns.length === 0) {
        return <span className="text-ink-muted">None</span>;
    }

    return (
        <ul className="flex flex-col gap-0.5 text-sm">
            {concerns.map((concern) => (
                <li key={concern.classSubjectId} className="text-ink">
                    {concern.subject}
                    {concern.grade !== null && <span className="tabular-nums"> · {formatGrade(concern.grade)}</span>}
                    {concern.standing !== null && ` · ${concern.standing.label}`}
                    {concern.missingScores > 0 && ` · ${concern.missingScores} missing`}
                </li>
            ))}
        </ul>
    );
}

interface TableProps {
    candidates: MonitoredCandidateRow[];
    singleClass: boolean;
    hasThresholds: boolean;
}

function OverallTable({ candidates, standingLabel, singleClass, hasThresholds }: TableProps & { standingLabel: string }) {
    return (
        <Table caption="Monitored candidates" className="min-w-[36rem]">
            <TableHead>
                <Th>Candidate No.</Th>
                <Th>Name</Th>
                {!singleClass && <Th className="hidden xl:table-cell">{terms.classBatch.singular}</Th>}
                {hasThresholds && <Th>{standingLabel}</Th>}
                <Th>Subjects of Concern</Th>
                <Th align="right" className="hidden xl:table-cell">
                    Lowest Grade
                </Th>
                <Th align="right">
                    <span className="sr-only">Actions</span>
                </Th>
            </TableHead>
            <TableBody>
                {candidates.map((row) => (
                    <Tr key={row.candidate.id}>
                        <Td className="font-medium text-ink" numeric>
                            {row.candidate.candidateNumber}
                        </Td>
                        <Td>
                            <CandidateCell row={row} />
                        </Td>
                        {!singleClass && <Td className="hidden text-ink-muted xl:table-cell">{row.classBatch.name}</Td>}
                        {hasThresholds && (
                            <Td>
                                <span className="flex flex-col items-start gap-0.5">
                                    <StandingBadge standing={row.overall.standing} />
                                    {row.overall.standing !== null && (
                                        <span className="text-xs text-ink-muted">
                                            {row.overall.basedOnSubjects} of {row.overall.totalSubjects}{' '}
                                            {row.overall.totalSubjects === 1 ? 'subject' : 'subjects'}
                                            {row.overall.isProvisional && ' · in progress'}
                                        </span>
                                    )}
                                </span>
                            </Td>
                        )}
                        <Td>
                            <ConcernList concerns={row.concerns} />
                        </Td>
                        <Td align="right" numeric className="hidden xl:table-cell">
                            {row.lowest === null ? (
                                '—'
                            ) : (
                                <span className="flex flex-col items-end">
                                    <span className="text-ink">{formatGrade(row.lowest.grade)}</span>
                                    <span className="text-xs text-ink-muted">
                                        {row.lowest.subject}
                                        {row.lowest.isProvisional && ' · in progress'}
                                    </span>
                                </span>
                            )}
                        </Td>
                        <Td align="right">
                            <RowAction href={routes.candidates.show(row.candidate.id)} label={`View ${row.candidate.name}`}>
                                View
                            </RowAction>
                        </Td>
                    </Tr>
                ))}
            </TableBody>
        </Table>
    );
}

function SubjectTable({ candidates, subjectName, singleClass, hasThresholds }: TableProps & { subjectName: string }) {
    return (
        <Table caption={`Monitored candidates in ${subjectName}`} className="min-w-[36rem]">
            <TableHead>
                <Th>Candidate No.</Th>
                <Th>Name</Th>
                {!singleClass && <Th className="hidden xl:table-cell">{terms.classBatch.singular}</Th>}
                <Th align="right">Current Grade</Th>
                <Th>{hasThresholds ? 'Current Standing' : 'Grade Status'}</Th>
                <Th align="right">
                    <span className="sr-only">Actions</span>
                </Th>
            </TableHead>
            <TableBody>
                {candidates.map((row) => (
                    <Tr key={row.candidate.id}>
                        <Td className="font-medium text-ink" numeric>
                            {row.candidate.candidateNumber}
                        </Td>
                        <Td>
                            <CandidateCell row={row} />
                        </Td>
                        {!singleClass && <Td className="hidden text-ink-muted xl:table-cell">{row.classBatch.name}</Td>}
                        <Td align="right" numeric className="font-semibold text-ink">
                            {formatGrade(row.subjectResult?.result.grade ?? null)}
                        </Td>
                        <Td>
                            {row.subjectResult === null ? (
                                <StandingBadge standing={null} />
                            ) : hasThresholds ? (
                                <StandingCell result={row.subjectResult.result} />
                            ) : (
                                <GradeStatusBadge result={row.subjectResult.result} />
                            )}
                        </Td>
                        <Td align="right">
                            <div className="flex justify-end gap-1">
                                {row.subjectResult?.canOpenGradebook && (
                                    <RowAction
                                        href={routes.teaching.gradebook(row.classBatch.id, row.subjectResult.classSubjectId)}
                                        label={`Gradebook for ${subjectName}, ${row.classBatch.name}`}
                                    >
                                        Gradebook
                                    </RowAction>
                                )}
                                <RowAction href={routes.candidates.show(row.candidate.id)} label={`View ${row.candidate.name}`}>
                                    View
                                </RowAction>
                            </div>
                        </Td>
                    </Tr>
                ))}
            </TableBody>
        </Table>
    );
}
