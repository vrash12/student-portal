import { Head } from '@inertiajs/react';
import { Apple, Settings2 } from 'lucide-react';
import { CampusFilter } from '@/components/academic/campus-filter';
import { ListCharts, type ListChart } from '@/components/charts/list-charts';
import { formatKg, formatWeightChange, NutritionStatusLabel } from '@/components/nutrition/nutrition-ui';
import { ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar } from '@/components/ui/filter-bar';
import { FormField, SelectInput, TextInput } from '@/components/ui/form-field';
import { MetricCard } from '@/components/ui/metric-card';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatCalendarDate } from '@/lib/format';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { CampusOption, Paginated } from '@/types';
import type { NutritionCounts, NutritionRow, NutritionStandards, Option } from '@/types/nutrition';

interface NutritionIndexProps {
    candidates: Paginated<NutritionRow>;
    counts: NutritionCounts;
    charts: ListChart[];
    standards: NutritionStandards;
    filters: { search: string; campus: string; class: string; show: string };
    campusOptions: CampusOption[];
    classes: Array<{ id: number; name: string }>;
    statusOptions: Option[];
    can: { configure: boolean };
}

const SHOW_OPTIONS: Option[] = [
    { value: 'attention', label: 'Needs attention' },
    { value: 'not_assessed', label: 'Not assessed yet' },
    { value: 'review_due', label: 'Review due' },
    { value: 'waist_risk', label: 'Waist at risk' },
];

/** Candidates of the campus with their latest nutrition assessment. Dietitians and administrators. */
export default function NutritionIndex({ candidates, counts, charts, standards, filters, campusOptions, classes, statusOptions, can }: NutritionIndexProps) {
    const { values, update, updateMany } = useQueryFilters(routes.nutrition.index(), filters);
    const filtered = values.search !== '' || values.campus !== '' || values.class !== '' || values.show !== '';

    return (
        <>
            <Head title="Nutrition" />

            <PageHeader
                title="Nutrition"
                description={`Confidential. Latest assessment of each candidate. BMI: underweight below ${standards.underweightBelow}, overweight from ${standards.overweightFrom}, obese from ${standards.obeseFrom}.`}
                actions={
                    can.configure && (
                        <ButtonLink href={routes.nutrition.standards()} icon={<Settings2 className="size-4" aria-hidden="true" />}>
                            Standards
                        </ButtonLink>
                    )
                }
            />

            <dl className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <MetricCard label="Needs Attention" value={counts.needsAttention} description={`of ${counts.total} candidates`} />
                <MetricCard label="Review Due" value={counts.reviewDue} />
                <MetricCard label="Not Assessed Yet" value={counts.notAssessed} />
                <MetricCard label="Waist at Risk" value={counts.waistAtRisk} description={`Waist ÷ height ≥ ${standards.waistToHeightRisk.toFixed(2)}`} />
            </dl>

            <ListCharts charts={charts} />

            <section className="rounded-lg border border-line-box bg-surface" aria-label="Candidate nutrition">
                <FilterBar onReset={() => updateMany({ search: '', campus: '', class: '', show: '' })} canReset={filtered}>
                    <FormField label="Search" className="sm:w-64">
                        <TextInput type="search" value={values.search} onChange={(event) => update('search', event.target.value)} placeholder="Candidate number or name" />
                    </FormField>
                    <CampusFilter options={campusOptions} value={values.campus} onChange={(campus) => updateMany({ campus, class: '' })} />
                    <FormField label={terms.classBatch.singular} className="sm:w-52">
                        <SelectInput value={values.class} onChange={(event) => update('class', event.target.value)}>
                            <option value="">All {terms.classBatch.plural.toLowerCase()}</option>
                            {classes.map((classBatch) => (
                                <option key={classBatch.id} value={String(classBatch.id)}>
                                    {classBatch.name}
                                </option>
                            ))}
                        </SelectInput>
                    </FormField>
                    <FormField label="Show" className="sm:w-52">
                        <SelectInput value={values.show} onChange={(event) => update('show', event.target.value)}>
                            <option value="">All candidates</option>
                            {SHOW_OPTIONS.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                            <optgroup label="BMI category">
                                {statusOptions.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </optgroup>
                        </SelectInput>
                    </FormField>
                </FilterBar>

                {candidates.data.length === 0 ? (
                    <EmptyState
                        icon={Apple}
                        title={filtered ? 'No candidates match' : 'No candidates yet'}
                        description={filtered ? 'Change the search or the filters.' : 'Candidates of your campus appear here once they are enrolled.'}
                    />
                ) : (
                    <Table caption="Candidate nutrition" className="min-w-[56rem]">
                        <TableHead>
                            <Th>Candidate</Th>
                            <Th>{terms.classBatch.singular}</Th>
                            <Th>Last Assessed</Th>
                            <Th align="right">Weight</Th>
                            <Th>BMI</Th>
                            <Th align="right">Waist ÷ Height</Th>
                            <Th>Next Review</Th>
                            <Th align="right">
                                <span className="sr-only">Actions</span>
                            </Th>
                        </TableHead>
                        <TableBody>
                            {candidates.data.map((row) => (
                                <Tr key={row.id}>
                                    <Td className="text-ink">
                                        <span className="font-medium">{row.name}</span>
                                        <span className="block text-xs text-ink-muted">{row.number}</span>
                                    </Td>
                                    <Td className="whitespace-nowrap text-ink">{row.className ?? '—'}</Td>
                                    <Td className="whitespace-nowrap text-ink">{row.assessedOn === null ? <span className="text-ink-muted">Never</span> : formatCalendarDate(row.assessedOn)}</Td>
                                    <Td align="right" numeric className="whitespace-nowrap text-ink">
                                        {formatKg(row.weightKg)}
                                        {row.weightChange !== null && <span className="block text-xs text-ink-muted">{formatWeightChange(row.weightChange)}</span>}
                                    </Td>
                                    <Td className="whitespace-nowrap text-ink">
                                        <span className="flex items-center gap-2">
                                            {row.bmi !== null && <span className="tabular-nums">{row.bmi.toFixed(1)}</span>}
                                            <NutritionStatusLabel status={row.status} />
                                        </span>
                                    </Td>
                                    <Td align="right" numeric className="whitespace-nowrap text-ink">
                                        {row.waistToHeight === null ? '—' : row.waistToHeight.toFixed(2)}
                                        {row.waistAtRisk && <span className="block text-xs font-medium text-warning-fg">At risk</span>}
                                    </Td>
                                    <Td className="whitespace-nowrap text-ink">
                                        {row.nextReviewOn === null ? '—' : formatCalendarDate(row.nextReviewOn)}
                                        {row.reviewDue && (
                                            <StatusBadge tone="warning" className="ml-2">
                                                Due
                                            </StatusBadge>
                                        )}
                                    </Td>
                                    <Td align="right">
                                        <RowAction href={routes.nutrition.show(row.id)} label={`Open the nutrition record of ${row.name}`}>
                                            Open
                                        </RowAction>
                                    </Td>
                                </Tr>
                            ))}
                        </TableBody>
                    </Table>
                )}

                <Pagination page={candidates} noun={{ one: 'candidate', other: 'candidates' }} />
            </section>
        </>
    );
}
