import { Head, Link, usePage } from '@inertiajs/react';
import { Award, Printer, SearchX, SlidersHorizontal, UsersRound } from 'lucide-react';
import type { ClassOptionGroup } from '@/components/candidates/candidate-form';
import { CandidateUnit } from '@/components/candidates/candidate-unit';
import { BarList } from '@/components/charts/bar-list';
import { ChartFigure } from '@/components/charts/chart-figure';
import { AreaStatusBadge, QualificationBadge, areaRuleLabel, formatAreaGrade } from '@/components/performance/area-status';
import { QualificationDistribution } from '@/components/performance/qualification-distribution';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { ClientPagination, useClientPagination } from '@/components/ui/client-pagination';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar } from '@/components/ui/filter-bar';
import { FormField, SelectInput } from '@/components/ui/form-field';
import { MetricCard } from '@/components/ui/metric-card';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { Table, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatPercent, useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { AreaCount, CandidateQualificationData, PerformanceAreaSummary, QualificationCounts } from '@/types/performance';

interface QualificationFilters {
    class: string;
    company: string;
    platoon: string;
    status: string;
    [key: string]: string;
}

interface QualificationPageProps {
    /** Classes grouped by academic period, active period first. */
    classOptions: ClassOptionGroup[];
    filters: QualificationFilters;
    /** Company and platoon names recorded in the selected class. */
    companyOptions: string[];
    platoonOptions: string[];
    classBatch: { id: number; name: string; period: string; isActivePeriod: boolean } | null;
    /** The active areas, in area order: one column each. */
    areas: PerformanceAreaSummary[];
    /** Candidates shown, in class rank order (unranked last). */
    rows: CandidateQualificationData[];
    /** Of the company/platoon shown, before the status filter. */
    counts: QualificationCounts;
    areaCounts: AreaCount[];
    /** Candidates of the class who are not withdrawn (the rank population). */
    classSize: number;
    generatedAt: string;
    can: { configure: boolean; viewCandidates: boolean };
}

const STATUS_OPTIONS = [
    { value: 'qualified', label: 'Qualified' },
    { value: 'not_qualified', label: 'Not Qualified' },
    { value: 'pending', label: 'Pending' },
];

const PRINT_STYLES =
    '@media print { @page { size: landscape; margin: 10mm; } #main-content { padding: 0; } table { font-size: 8.5pt; } th, td { padding: 4pt 5pt; } tr { break-inside: avoid; } .qualification-table > div { overflow: visible; } }';

export default function Qualification({
    classOptions,
    filters,
    companyOptions,
    platoonOptions,
    classBatch,
    areas,
    rows,
    counts,
    areaCounts,
    classSize,
    generatedAt,
    can,
}: QualificationPageProps) {
    const { app } = usePage().props;
    const dates = useDateFormatter();
    const classTerm = terms.classBatch.singular;
    const { values, update, updateMany } = useQueryFilters(routes.qualification.index(), filters);
    const pagination = useClientPagination(rows);
    const canReset = values.company !== '' || values.platoon !== '' || values.status !== '';
    const resetFilters = () => updateMany({ company: '', platoon: '', status: '' });
    const scopeParts = [
        filters.company !== '' ? filters.company : null,
        filters.platoon !== '' ? filters.platoon : null,
        filters.status !== '' ? STATUS_OPTIONS.find((option) => option.value === filters.status)?.label : null,
    ].filter((part): part is string => typeof part === 'string');

    return (
        <>
            <Head title="Qualification" />
            <style>{PRINT_STYLES}</style>

            <div className="print:hidden">
                <PageHeader
                    title="Qualification & Class Rank"
                    actions={
                        <>
                            {can.configure && (
                                <ButtonLink href={routes.performanceAreas.index()} icon={<SlidersHorizontal className="size-4" aria-hidden="true" />}>
                                    Edit Performance Areas
                                </ButtonLink>
                            )}
                            {classBatch !== null && (
                                <Button variant="secondary" onClick={() => window.print()} icon={<Printer className="size-4" aria-hidden="true" />}>
                                    Print
                                </Button>
                            )}
                        </>
                    }
                />
            </div>

            {classBatch === null ? (
                <section className="rounded-lg border border-line-box bg-surface">
                    <EmptyState
                        icon={UsersRound}
                        title={`No ${terms.classBatch.plural.toLowerCase()} yet`}
                        description={`Create a ${classTerm.toLowerCase()} and enrol candidates first.`}
                    />
                </section>
            ) : (
                <div className="flex flex-col gap-6">
                    <section className="rounded-lg border border-line-box bg-surface print:border-0" aria-labelledby="qualification-context">
                        <div className="print:hidden">
                            <FilterBar onReset={resetFilters} canReset={canReset}>
                                <FormField label={classTerm} className="sm:w-60">
                                    <SelectInput
                                        value={values.class}
                                        onChange={(event) => updateMany({ class: event.target.value, company: '', platoon: '', status: '' })}
                                    >
                                        {classOptions.map((group) => (
                                            <optgroup key={group.period} label={`${group.period}${group.isActive ? ' (active)' : ''}`}>
                                                {group.classes.map((option) => (
                                                    <option key={option.id} value={String(option.id)}>
                                                        {option.name}
                                                    </option>
                                                ))}
                                            </optgroup>
                                        ))}
                                    </SelectInput>
                                </FormField>
                                {companyOptions.length > 0 && (
                                    <FormField label="Company" className="sm:w-48">
                                        <SelectInput value={values.company} onChange={(event) => update('company', event.target.value)}>
                                            <option value="">All companies</option>
                                            {companyOptions.map((company) => (
                                                <option key={company} value={company}>
                                                    {company}
                                                </option>
                                            ))}
                                        </SelectInput>
                                    </FormField>
                                )}
                                {platoonOptions.length > 0 && (
                                    <FormField label="Platoon" className="sm:w-48">
                                        <SelectInput value={values.platoon} onChange={(event) => update('platoon', event.target.value)}>
                                            <option value="">All platoons</option>
                                            {platoonOptions.map((platoon) => (
                                                <option key={platoon} value={platoon}>
                                                    {platoon}
                                                </option>
                                            ))}
                                        </SelectInput>
                                    </FormField>
                                )}
                                <FormField label="Qualification" className="sm:w-48">
                                    <SelectInput value={values.status} onChange={(event) => update('status', event.target.value)}>
                                        <option value="">All statuses</option>
                                        {STATUS_OPTIONS.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </SelectInput>
                                </FormField>
                            </FilterBar>
                        </div>

                        <div className="p-5 print:px-0">
                            <h2 id="qualification-context" className="text-lg font-semibold text-primary-900">
                                <span className="hidden print:inline">Qualification & Class Rank · </span>
                                {classBatch.name}
                            </h2>
                            <p className="mt-1 text-sm text-ink-muted">
                                {classBatch.period}
                                {classBatch.isActivePeriod ? ' (active period)' : ''} · <span className="tabular-nums">{classSize}</span>{' '}
                                {classSize === 1 ? 'candidate' : 'candidates'} ranked together
                                {scopeParts.length > 0 ? ` · Showing: ${scopeParts.join(', ')}` : ''}
                            </p>
                            <p className="mt-1 text-sm text-ink-muted">
                                Generated <time dateTime={generatedAt}>{dates.dateTime(generatedAt)}</time> ({app.timezone})
                            </p>
                        </div>
                    </section>

                    {areas.length === 0 && (
                        <Alert tone="warning" title="No active performance areas">
                            Every candidate is Pending.
                            {can.configure && (
                                <span className="mt-2 block print:hidden">
                                    <ButtonLink href={routes.performanceAreas.create()} variant="secondary">
                                        Add Performance Area
                                    </ButtonLink>
                                </span>
                            )}
                        </Alert>
                    )}

                    {counts.total > 0 && (
                        <Panel
                            title="Summary"
                            description={
                                filters.company !== '' || filters.platoon !== ''
                                    ? 'Company and platoon shown, all statuses.'
                                    : `Whole ${classTerm.toLowerCase()}, all statuses.`
                            }
                            className="print:break-inside-avoid"
                        >
                            <div className="flex flex-col gap-6">
                                <dl className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                    <MetricCard label="Candidates" value={counts.total} />
                                    <MetricCard label="Qualified" value={counts.qualified} />
                                    <MetricCard label="Pending" value={counts.pending} description="A must-pass area is incomplete or has no results yet." />
                                    <MetricCard label="Not Qualified" value={counts.notQualified} description="A must-pass area failed." />
                                </dl>
                                <div className="grid gap-6 lg:grid-cols-2">
                                    <ChartFigure title="Qualification status">
                                        <QualificationDistribution counts={counts} />
                                    </ChartFigure>
                                    {areaCounts.length > 0 && (
                                        <ChartFigure title="Candidates passing each area">
                                            <BarList
                                                bars={areaCounts.map((area) => ({ label: area.name, value: area.passRate }))}
                                                renderLabel={(bar, index) => {
                                                    const area = areaCounts[index];

                                                    return (
                                                        <>
                                                            {bar.label}
                                                            {area !== undefined && (
                                                                <span className="block text-xs font-normal text-ink-muted">
                                                                    {area.passed} passed · {area.failed} failed · {area.incomplete + area.notYet} pending
                                                                </span>
                                                            )}
                                                        </>
                                                    );
                                                }}
                                                emptyValue="No candidates"
                                                formatValue={formatPercent}
                                            />
                                        </ChartFigure>
                                    )}
                                </div>
                            </div>
                        </Panel>
                    )}

                    <section className="rounded-lg border border-line-box bg-surface print:border-0" aria-label="Qualification of candidates">
                        {classSize === 0 ? (
                            <EmptyState
                                icon={Award}
                                title={`No candidates in this ${classTerm.toLowerCase()}`}
                                description="Withdrawn candidates are not listed."
                            />
                        ) : rows.length === 0 ? (
                            <EmptyState
                                icon={SearchX}
                                title="No candidates match these filters"
                                description="Try other filters."
                                action={
                                    <Button variant="secondary" onClick={resetFilters}>
                                        Reset Filters
                                    </Button>
                                }
                            />
                        ) : (
                            <div className="qualification-table">
                                <Table caption={`Qualification and class rank, ${classBatch.name}`} className="min-w-[64rem]">
                                    <TableHead>
                                        <Th align="right">Rank</Th>
                                        <Th>Candidate</Th>
                                        <Th>Company / Platoon</Th>
                                        {areas.map((area) => (
                                            <Th key={area.id} className="min-w-36">
                                                {area.name}
                                                <span className="block font-normal normal-case tracking-normal text-primary-700">{areaRuleLabel(area)}</span>
                                            </Th>
                                        ))}
                                        <Th align="right">Overall</Th>
                                        <Th className="min-w-48">Qualification</Th>
                                    </TableHead>
                                    <tbody className="divide-y divide-line print:hidden">
                                        {pagination.rows.map((row) => (
                                            <QualificationRow key={row.candidate.id} row={row} areas={areas} linkCandidate={can.viewCandidates} />
                                        ))}
                                    </tbody>
                                    {/* The printed report always lists every candidate, whatever page is on screen. */}
                                    <tbody className="hidden divide-y divide-line print:table-row-group">
                                        {rows.map((row) => (
                                            <QualificationRow key={row.candidate.id} row={row} areas={areas} linkCandidate={can.viewCandidates} />
                                        ))}
                                    </tbody>
                                </Table>
                                <div className="print:hidden">
                                    <ClientPagination pagination={pagination} noun={{ one: 'candidate', other: 'candidates' }} label="Qualification pages" />
                                </div>
                            </div>
                        )}
                    </section>

                    <p className="text-sm text-ink-muted">
                        Class rank covers the whole {classTerm.toLowerCase()}; filters don't change it. Partial: a weighted area has no grade yet. Subject standing is
                        not affected.
                    </p>
                </div>
            )}
        </>
    );
}

function QualificationRow({ row, areas, linkCandidate }: { row: CandidateQualificationData; areas: PerformanceAreaSummary[]; linkCandidate: boolean }) {
    const results = new Map(row.areas.map((result) => [result.areaId, result]));
    const rank = row.rank ?? null;
    const { candidate, overall, qualification } = row;

    return (
        <Tr>
            <Td align="right" numeric className="text-base font-semibold text-ink">
                {rank === null ? (
                    <>
                        <span aria-hidden="true">—</span>
                        <span className="sr-only">Unranked</span>
                    </>
                ) : (
                    rank
                )}
            </Td>
            <Td className="text-ink">
                {linkCandidate ? (
                    <Link href={routes.candidates.show(candidate.id)} className="font-medium text-primary-700 hover:underline">
                        {candidate.name}
                    </Link>
                ) : (
                    <span className="font-medium">{candidate.name}</span>
                )}
                <span className="block text-xs text-ink-muted tabular-nums">
                    {candidate.candidateNumber}
                    {candidate.status.value !== 'enrolled' ? ` · ${candidate.status.label}` : ''}
                </span>
            </Td>
            <Td className="text-sm">
                <CandidateUnit company={candidate.company} platoon={candidate.platoon} />
            </Td>
            {areas.map((area) => {
                const result = results.get(area.id);

                return (
                    <Td key={area.id}>
                        {result === undefined ? (
                            <span className="text-ink-muted">—</span>
                        ) : (
                            <div className="flex flex-col items-start gap-1">
                                <span className="font-semibold text-ink tabular-nums">{formatAreaGrade(result.grade)}</span>
                                <AreaStatusBadge status={result.status} />
                                {result.status.value !== 'passed' && result.note && <span className="max-w-48 text-xs text-ink-muted">{result.note}</span>}
                            </div>
                        )}
                    </Td>
                );
            })}
            <Td align="right" numeric className="text-ink">
                <span className="font-semibold">{formatAreaGrade(overall.score)}</span>
                {overall.score !== null && !overall.complete && <span className="block text-xs text-ink-muted">Partial</span>}
            </Td>
            <Td>
                <div className="flex flex-col items-start gap-1">
                    <QualificationBadge status={qualification.status} />
                    {qualification.reasons.length > 0 && (
                        <ul className="text-xs text-ink">
                            {qualification.reasons.map((reason) => (
                                <li key={reason}>{reason}</li>
                            ))}
                        </ul>
                    )}
                    {qualification.pending.length > 0 && (
                        <span className="text-xs text-ink-muted">Waiting for: {qualification.pending.join(', ')}</span>
                    )}
                </div>
            </Td>
        </Tr>
    );
}
