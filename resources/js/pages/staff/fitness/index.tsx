import { Head } from '@inertiajs/react';
import { Dumbbell, Plus, SlidersHorizontal } from 'lucide-react';
import { CampusFilter } from '@/components/academic/campus-filter';
import { ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar } from '@/components/ui/filter-bar';
import { FormField, SelectInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatCalendarDate } from '@/lib/format';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import { withQuery } from '@/lib/url';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { CampusOption, Paginated } from '@/types';

interface FitnessTestRow {
    id: number;
    title: string;
    testedOn: string;
    classBatch: { id: number; name: string };
    eventCount: number;
    /** Candidates of the class who are not withdrawn. */
    rosterCount: number;
    summary: { passed: number; failed: number; incomplete: number; recorded: number };
}

interface FitnessIndexProps {
    tests: Paginated<FitnessTestRow>;
    filters: { period: string; campus: string; class: string };
    /** Campuses to filter by (accounts that see every campus only). */
    campusOptions: CampusOption[];
    periods: Array<{ id: number; name: string }>;
    classes: Array<{ id: number; name: string }>;
    /** "all": every class; "taught": only the classes the user teaches. */
    scope: 'all' | 'taught';
    can: { manage: boolean; configure: boolean };
}

export default function FitnessIndex({ tests, filters, campusOptions, periods, classes, scope, can }: FitnessIndexProps) {
    const { singular, plural } = terms.classBatch;
    const defaultPeriod = String(periods[0]?.id ?? '');
    const { values, update, updateMany } = useQueryFilters(routes.fitness.index(), { period: filters.period, campus: filters.campus, class: filters.class });
    const canReset = values.period !== defaultPeriod || values.campus !== '' || values.class !== '';

    const newTestAction = can.manage && (
        <ButtonLink href={withQuery(routes.fitness.tests.create(), { class: values.class })} variant="primary" icon={<Plus className="size-4" aria-hidden="true" />}>
            New Fitness Test
        </ButtonLink>
    );

    return (
        <>
            <Head title="Military Fitness" />

            <PageHeader
                title="Military Fitness"
                description={`${scope === 'taught' ? `Fitness tests of the ${plural.toLowerCase()} you teach` : `Standard fitness tests of each ${singular.toLowerCase()}`} and their results. A candidate passes a test by reaching the passing points of every event.`}
                actions={
                    (can.configure || can.manage) && (
                        <>
                            {can.configure && (
                                <ButtonLink href={routes.fitness.standards.index()} icon={<SlidersHorizontal className="size-4" aria-hidden="true" />}>
                                    Events and Points
                                </ButtonLink>
                            )}
                            {newTestAction}
                        </>
                    )
                }
            />

            <section className="rounded-lg border border-line-box bg-surface" aria-label="Fitness tests">
                <FilterBar onReset={() => updateMany({ period: defaultPeriod, campus: '', class: '' })} canReset={canReset}>
                    <FormField label="Academic period" className="sm:w-60">
                        <SelectInput value={values.period} onChange={(event) => updateMany({ period: event.target.value, class: '' })}>
                            {periods.length === 0 && <option value="">No academic periods</option>}
                            {periods.map((period) => (
                                <option key={period.id} value={String(period.id)}>
                                    {period.name}
                                </option>
                            ))}
                        </SelectInput>
                    </FormField>
                    <CampusFilter options={campusOptions} value={values.campus} onChange={(campus) => updateMany({ campus, class: '' })} />
                    <FormField label={singular} className="sm:w-60">
                        <SelectInput value={values.class} onChange={(event) => update('class', event.target.value)}>
                            <option value="">All {plural.toLowerCase()}</option>
                            {classes.map((classBatch) => (
                                <option key={classBatch.id} value={String(classBatch.id)}>
                                    {classBatch.name}
                                </option>
                            ))}
                        </SelectInput>
                    </FormField>
                </FilterBar>

                {tests.data.length === 0 ? (
                    <EmptyState
                        icon={Dumbbell}
                        title="No fitness tests"
                        description={
                            can.manage
                                ? `No fitness tests are recorded for the selected ${plural.toLowerCase()}. Create a test, then record each candidate's results.`
                                : `No fitness tests are recorded for the selected ${plural.toLowerCase()}.`
                        }
                        action={newTestAction || undefined}
                    />
                ) : (
                    <Table caption="Fitness tests" className="min-w-[52rem]">
                        <TableHead>
                            <Th>Date</Th>
                            <Th>Test</Th>
                            <Th>{singular}</Th>
                            <Th align="right">Results</Th>
                            <Th align="right">Passed</Th>
                            <Th align="right">Failed</Th>
                            <Th align="right">Incomplete</Th>
                            <Th align="right">
                                <span className="sr-only">Actions</span>
                            </Th>
                        </TableHead>
                        <TableBody>
                            {tests.data.map((test) => (
                                <Tr key={test.id}>
                                    <Td className="whitespace-nowrap text-ink">{formatCalendarDate(test.testedOn)}</Td>
                                    <Td className="text-ink">
                                        <span className="font-medium">{test.title}</span>
                                        <span className="block text-xs text-ink-muted">
                                            {test.eventCount} {test.eventCount === 1 ? 'event' : 'events'}
                                        </span>
                                    </Td>
                                    <Td className="text-ink">{test.classBatch.name}</Td>
                                    <Td align="right" numeric className="text-ink">
                                        {test.summary.recorded} of {test.rosterCount}
                                    </Td>
                                    <Td align="right" numeric className="text-ink">
                                        {test.summary.passed}
                                    </Td>
                                    <Td align="right" numeric className={test.summary.failed > 0 ? 'font-semibold text-danger-fg' : 'text-ink'}>
                                        {test.summary.failed}
                                    </Td>
                                    <Td align="right" numeric className="text-ink">
                                        {test.summary.incomplete}
                                    </Td>
                                    <Td align="right">
                                        <RowAction href={routes.fitness.tests.show(test.id)} label={`Open ${test.title}, ${test.classBatch.name}`}>
                                            {can.manage ? 'Results' : 'View'}
                                        </RowAction>
                                    </Td>
                                </Tr>
                            ))}
                        </TableBody>
                    </Table>
                )}

                <Pagination page={tests} noun={{ one: 'test', other: 'tests' }} />
            </section>
        </>
    );
}
