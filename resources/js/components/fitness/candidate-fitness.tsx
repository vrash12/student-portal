import { Dumbbell } from 'lucide-react';
import { EmptyState } from '@/components/ui/empty-state';
import { Panel } from '@/components/ui/panel';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatCalendarDate, formatGrade, formatPoints } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { CandidateFitnessTest } from '@/types/fitness';

/**
 * A candidate's military fitness on their profile: the latest test event by
 * event, then earlier tests. Fitness is a separate record and does not
 * affect academic standing.
 */
export function CandidateFitness({ tests }: { tests: CandidateFitnessTest[] }) {
    const [latest, ...earlier] = tests;

    return (
        <Panel
            title="Military Fitness"
            description="Standard fitness test results. Separate from academic grades and standing."
            bodyClassName={latest === undefined ? undefined : 'p-0'}
        >
            {latest === undefined ? (
                <EmptyState icon={Dumbbell} headingLevel="h3" title="No fitness tests yet" description="Fitness tests of the candidate's class appear here once they are created." />
            ) : (
                <div className="flex flex-col">
                    <div className="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
                        <div>
                            <h3 className="font-semibold text-ink">{latest.title}</h3>
                            <p className="text-sm text-ink-muted">
                                {formatCalendarDate(latest.testedOn)} · {latest.classBatch}
                            </p>
                        </div>
                        <div className="flex items-center gap-3">
                            <StatusBadge tone={latest.outcome.status.tone as StatusTone}>{latest.outcome.status.label}</StatusBadge>
                            {latest.outcome.points !== null && <span className="font-semibold text-ink tabular-nums">{formatGrade(latest.outcome.points)} pts</span>}
                            <RowAction href={routes.fitness.tests.show(latest.id)} label={`Open ${latest.title}`}>
                                Open Test
                            </RowAction>
                        </div>
                    </div>
                    <Table caption={`Results of ${latest.title}`} className="min-w-[36rem]">
                        <TableHead>
                            <Th>Event</Th>
                            <Th align="right">Result</Th>
                            <Th align="right">Passing / Best</Th>
                            <Th align="right">Points</Th>
                            <Th>Outcome</Th>
                        </TableHead>
                        <TableBody>
                            {latest.events.map((event) => (
                                <Tr key={event.id}>
                                    <Td className="font-medium text-ink">{event.name}</Td>
                                    <Td align="right" numeric className="text-ink">
                                        {event.result?.display ?? '—'}
                                    </Td>
                                    <Td align="right" numeric className="text-ink-muted">
                                        {event.passingDisplay} ({formatPoints(event.passingPoints)} pts) / {event.maximumDisplay}
                                    </Td>
                                    <Td align="right" numeric className="text-ink">
                                        {event.result === null ? '—' : formatGrade(event.result.points)}
                                    </Td>
                                    <Td>
                                        {event.result === null ? (
                                            <StatusBadge tone="neutral">Not Recorded</StatusBadge>
                                        ) : event.result.passed ? (
                                            <StatusBadge tone="success">Passed</StatusBadge>
                                        ) : (
                                            <StatusBadge tone="danger">Below Standard</StatusBadge>
                                        )}
                                    </Td>
                                </Tr>
                            ))}
                        </TableBody>
                    </Table>

                    {earlier.length > 0 && (
                        <div className="border-t border-line">
                            <h3 className="px-5 pt-4 text-sm font-semibold text-ink">Earlier Tests</h3>
                            <ul className="divide-y divide-line">
                                {earlier.map((test) => (
                                    <li key={test.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                                        <div>
                                            <p className="font-medium text-ink">{test.title}</p>
                                            <p className="text-sm text-ink-muted">
                                                {formatCalendarDate(test.testedOn)} · {test.classBatch}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <StatusBadge tone={test.outcome.status.tone as StatusTone}>{test.outcome.status.label}</StatusBadge>
                                            {test.outcome.points !== null && <span className="text-sm font-semibold text-ink tabular-nums">{formatGrade(test.outcome.points)} pts</span>}
                                            <RowAction href={routes.fitness.tests.show(test.id)} label={`Open ${test.title}`}>
                                                Open
                                            </RowAction>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            )}
        </Panel>
    );
}
