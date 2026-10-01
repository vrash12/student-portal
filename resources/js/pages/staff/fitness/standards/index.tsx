import { Head } from '@inertiajs/react';
import { Dumbbell, Plus } from 'lucide-react';
import { ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { routes } from '@/lib/routes';

interface FitnessEventRow {
    id: number;
    name: string;
    description: string | null;
    unit: { value: string; label: string };
    higherIsBetter: boolean;
    passingDisplay: string;
    maximumDisplay: string;
    sortOrder: number;
    isActive: boolean;
    testCount: number;
}

export default function FitnessStandards({ events }: { events: FitnessEventRow[] }) {
    const addAction = (
        <ButtonLink href={routes.fitness.standards.create()} variant="primary" icon={<Plus className="size-4" aria-hidden="true" />}>
            Add Event
        </ButtonLink>
    );

    return (
        <>
            <Head title="Fitness Standards" />

            <PageHeader
                title="Fitness Standards"
                description="The events of a fitness test. Each event scores 60 points at its passing standard and 100 at its maximum; failing any event fails the test."
                breadcrumbs={[{ label: 'Military Fitness', href: routes.fitness.index() }, { label: 'Standards' }]}
                actions={addAction}
            />

            <div className="rounded-lg border border-line bg-surface">
                {events.length === 0 ? (
                    <EmptyState
                        icon={Dumbbell}
                        title="No fitness events yet"
                        description="Add the events candidates perform, such as push-ups, sit-ups, and a timed run, with their passing and maximum standards."
                        action={addAction}
                    />
                ) : (
                    <Table caption="Fitness events and standards" className="min-w-[48rem]">
                        <TableHead>
                            <Th align="right">Order</Th>
                            <Th>Event</Th>
                            <Th>Measured As</Th>
                            <Th align="right">Passing (60 pts)</Th>
                            <Th align="right">Maximum (100 pts)</Th>
                            <Th>Status</Th>
                            <Th align="right">Tests</Th>
                            <Th align="right">
                                <span className="sr-only">Actions</span>
                            </Th>
                        </TableHead>
                        <TableBody>
                            {events.map((event) => (
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
                                    <Td align="right" numeric className="text-ink">
                                        {event.passingDisplay}
                                    </Td>
                                    <Td align="right" numeric className="text-ink">
                                        {event.maximumDisplay}
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
            </div>
        </>
    );
}
