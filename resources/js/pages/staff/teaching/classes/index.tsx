import { Head } from '@inertiajs/react';
import { School } from 'lucide-react';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField, SelectInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import { useQueryFilters } from '@/lib/use-query-filters';

interface TaughtClass {
    id: number;
    name: string;
    subjects: Array<{ code: string; name: string }>;
    enrolledCount: number;
}

interface PeriodOption {
    id: number;
    name: string;
    isActive: boolean;
}

interface MyClassesProps {
    classes: TaughtClass[];
    periods: PeriodOption[];
    filters: { period: string; [key: string]: string };
}

export default function MyClasses({ classes, periods, filters }: MyClassesProps) {
    const { values, update } = useQueryFilters(routes.teaching.classes.index(), filters);
    const { singular, plural } = terms.classBatch;
    const title = `My ${plural}`;
    const shownPeriod = periods.find((period) => String(period.id) === filters.period) ?? null;

    return (
        <>
            <Head title={title} />

            <PageHeader
                title={title}
                description={
                    shownPeriod === null ? (
                        `${plural} where you teach at least one subject.`
                    ) : (
                        <>
                            {shownPeriod.name}{' '}
                            {shownPeriod.isActive ? (
                                <StatusBadge tone="success">Active period</StatusBadge>
                            ) : (
                                <StatusBadge tone="neutral">Inactive period</StatusBadge>
                            )}
                        </>
                    )
                }
            />

            <div className="rounded-lg border border-line bg-surface">
                {periods.length > 1 && (
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

                {classes.length === 0 ? (
                    <EmptyState
                        icon={School}
                        title="No teaching assignments"
                        description={`You are not assigned to teach any subjects yet. ${plural} appear here once an academic administrator assigns you.`}
                    />
                ) : (
                    <Table caption={title}>
                        <TableHead>
                            <Th>{singular}</Th>
                            <Th>Subjects You Teach</Th>
                            <Th align="right">Enrolled</Th>
                            <Th align="right">
                                <span className="sr-only">Actions</span>
                            </Th>
                        </TableHead>
                        <TableBody>
                            {classes.map((classBatch) => (
                                <Tr key={classBatch.id}>
                                    <Td className="font-medium text-ink">{classBatch.name}</Td>
                                    <Td className="text-ink">{classBatch.subjects.map((subject) => subject.name).join(', ')}</Td>
                                    <Td align="right" numeric>
                                        {classBatch.enrolledCount}
                                    </Td>
                                    <Td align="right">
                                        <RowAction href={routes.teaching.classes.show(classBatch.id)} label={`View ${classBatch.name}`}>
                                            View
                                        </RowAction>
                                    </Td>
                                </Tr>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </div>
        </>
    );
}
