import { Head } from '@inertiajs/react';
import { Dumbbell, Plus } from 'lucide-react';
import { ButtonLink } from '@/components/ui/button';
import { ClientPagination, useClientPagination } from '@/components/ui/client-pagination';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatPoints } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { FitnessPointsRow, FitnessScoringMethod } from '@/types/fitness';

interface FitnessEventRow {
    id: number;
    name: string;
    description: string | null;
    unit: { value: string; label: string };
    higherIsBetter: boolean;
    method: { value: FitnessScoringMethod; label: string };
    passingPoints: number;
    maximumPoints: number;
    passingDisplay: string;
    maximumDisplay: string;
    table: FitnessPointsRow[];
    sortOrder: number;
    isActive: boolean;
    testCount: number;
}

export default function FitnessStandards({ events }: { events: FitnessEventRow[] }) {
    const pagination = useClientPagination(events);
    const addAction = (
        <ButtonLink href={routes.fitness.standards.create()} variant="primary" icon={<Plus className="size-4" aria-hidden="true" />}>
            Add Event
        </ButtonLink>
    );

    return (
        <>
            <Head title="Fitness Events and Points" />

            <PageHeader
                title="Fitness Events and Points"
                description="The events of a fitness test and the points each result earns: a points table, or a scale between a passing and a maximum standard. A candidate passes an event with at least its passing points; failing any event fails the test."
                breadcrumbs={[{ label: 'Military Fitness', href: routes.fitness.index() }, { label: 'Events and Points' }]}
                actions={addAction}
            />

            <div className="rounded-lg border border-line bg-surface">
                {events.length === 0 ? (
                    <EmptyState
                        icon={Dumbbell}
                        title="No fitness events yet"
                        description="Add the events candidates perform, such as push-ups, sit-ups, and a timed run, with the points each result earns."
                        action={addAction}
                    />
                ) : (
                    <Table caption="Fitness events and standards" className="min-w-[56rem]">
                        <TableHead>
                            <Th align="right">Order</Th>
                            <Th>Event</Th>
                            <Th>Measured As</Th>
                            <Th>Scoring</Th>
                            <Th align="right">Passing</Th>
                            <Th align="right">Best</Th>
                            <Th>Status</Th>
                            <Th align="right">Tests</Th>
                            <Th align="right">
                                <span className="sr-only">Actions</span>
                            </Th>
                        </TableHead>
                        <TableBody>
                            {pagination.rows.map((event) => (
                                <Tr key={event.id}>
                                    <Td align="right" numeric>
                                        {event.sortOrder}
                                    </Td>
                                    <Td className="text-ink">
                                        <span className="font-medium">{event.name}</span>
                                        {event.description && <span className="block text-xs text-ink-muted">{event.description}</span>}
                                    </Td>
                                    <Td className="text-ink-muted">
                                        {event.unit.label} · {event.higherIsBetter ? 'higher is better' : 'lower is better'}
                                    </Td>
                                    <Td className="text-ink-muted">
                                        {event.method.value === 'table' ? `Points table · ${event.table.length} ${event.table.length === 1 ? 'row' : 'rows'}` : 'Scaled'}
                                    </Td>
                                    <Td align="right" numeric className="text-ink">
                                        {event.passingDisplay}
                                        <span className="block text-xs text-ink-muted">{formatPoints(event.passingPoints)} pts</span>
                                    </Td>
                                    <Td align="right" numeric className="text-ink">
                                        {event.maximumDisplay}
                                        <span className="block text-xs text-ink-muted">{formatPoints(event.maximumPoints)} pts</span>
                                    </Td>
                                    <Td>
                                        {event.isActive ? <StatusBadge tone="success">Active</StatusBadge> : <StatusBadge tone="neutral">Inactive</StatusBadge>}
                                    </Td>
                                    <Td align="right" numeric>
                                        {event.testCount}
                                    </Td>
                                    <Td align="right">
                                        <RowAction href={routes.fitness.standards.edit(event.id)} label={`Edit ${event.name}`}>
                                            Edit
                                        </RowAction>
                                    </Td>
                                </Tr>
                            ))}
                        </TableBody>
                    </Table>
                )}
                <ClientPagination pagination={pagination} noun={{ one: 'event', other: 'events' }} label="Fitness event pages" />
            </div>
        </>
    );
}
