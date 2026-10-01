import { Head } from '@inertiajs/react';
import { ListCharts, type ListChart } from '@/components/charts/list-charts';
import { Plus, UsersRound } from 'lucide-react';
import { ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField, SelectInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { Paginated } from '@/types';

interface ClassRow {
    id: number;
    name: string;
    period: string;
    candidateCount: number;
    subjectCount: number;
}

interface PeriodOption {
    id: number;
    name: string;
    isActive: boolean;
}

interface ClassesIndexProps {
    /** Charts computed by the server from the filtered list. */
    charts: ListChart[];
    classes: Paginated<ClassRow>;
    filters: { period: string; [key: string]: string };
    periods: PeriodOption[];
}

export default function ClassesIndex({ classes, filters, periods, charts }: ClassesIndexProps) {
    const { values, update } = useQueryFilters(routes.classes.index(), filters);
    const { singular, plural } = terms.classBatch;

    const createAction = (
        <ButtonLink href={routes.classes.create()} variant="primary" icon={<Plus className="size-4" aria-hidden="true" />}>
            Create {singular}
        </ButtonLink>
    );

    return (
        <>
            <Head title={plural} />

            <PageHeader
                title={plural}
                description={`Each ${singular.toLowerCase()} belongs to one academic period and takes a set of subjects.`}
                actions={periods.length > 0 && createAction}
            />

            <ListCharts charts={charts} />

            <div className="rounded-lg border border-line bg-surface">
                {periods.length > 0 && (
                    <div className="border-b border-line p-4">
                        <FormField label="Academic Period" className="sm:w-80">
                            <SelectInput value={values.period} onChange={(event) => update('period', event.target.value)}>
                                {periods.map((period) => (
                                    <option key={period.id} value={String(period.id)}>
                                        {period.name}
                                        {period.isActive ? ' (active)' : ''}
                                    </option>
                                ))}
                            </SelectInput>
                        </FormField>
                    </div>
                )}

                {periods.length === 0 ? (
                    <EmptyState
                        icon={UsersRound}
                        title="Create an academic period first"
                        description={`${plural} belong to an academic period. Create one before adding ${plural.toLowerCase()}.`}
                        action={
                            <ButtonLink href={routes.academicPeriods.create()} variant="primary">
                                Create Period
                            </ButtonLink>
                        }
                    />
                ) : classes.data.length === 0 ? (
                    <EmptyState
                        icon={UsersRound}
                        title={`No ${plural.toLowerCase()} in this period`}
                        description={`Create a ${singular.toLowerCase()} and add the subjects it takes.`}
                        action={createAction}
                    />
                ) : (
                    <Table caption={plural}>
                        <TableHead>
                            <Th>{singular}</Th>
                            <Th>Academic Period</Th>
                            <Th align="right">Candidates</Th>
                            <Th align="right">Subjects</Th>
                            <Th align="right">
                                <span className="sr-only">Actions</span>
                            </Th>
                        </TableHead>
                        <TableBody>
                            {classes.data.map((classBatch) => (
                                <Tr key={classBatch.id}>
                                    <Td className="font-medium text-ink">{classBatch.name}</Td>
                                    <Td className="text-ink-muted">{classBatch.period}</Td>
                                    <Td align="right" numeric>
                                        {classBatch.candidateCount}
                                    </Td>
                                    <Td align="right" numeric>
                                        {classBatch.subjectCount}
                                    </Td>
                                    <Td align="right">
                                        <RowAction href={routes.classes.show(classBatch.id)} label={`View ${classBatch.name}`}>
                                            View
                                        </RowAction>
                                    </Td>
                                </Tr>
                            ))}
                        </TableBody>
                    </Table>
                )}

                <Pagination page={classes} noun={{ one: singular.toLowerCase(), other: plural.toLowerCase() }} />
            </div>
        </>
    );
}
