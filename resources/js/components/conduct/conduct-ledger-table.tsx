import { Ban } from 'lucide-react';
import { formatPoints } from '@/components/conduct/conduct-totals';
import { Button } from '@/components/ui/button';
import { ClientPagination, useClientPagination } from '@/components/ui/client-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { cn } from '@/lib/cn';
import { formatCalendarDate, useDateFormatter } from '@/lib/format';
import type { ConductEntryRow } from '@/types/conduct';

interface ConductLedgerTableProps {
    /** Newest first, as ConductLedger::history returns them. */
    entries: ConductEntryRow[];
    caption?: string;
    /** Called with the entry to void; omit to show the ledger read-only. */
    onVoid?: (entry: ConductEntryRow) => void;
}

/**
 * A candidate's merits and demerits. Voided entries stay listed, struck
 * through, with the reason, and are marked as not counted (never by colour
 * alone).
 */
export function ConductLedgerTable({ entries, caption = 'Merits and demerits', onVoid }: ConductLedgerTableProps) {
    const formatDate = useDateFormatter();
    const pagination = useClientPagination(entries);

    return (
        <>
            <Table caption={caption} className="min-w-[52rem]">
                <TableHead>
                    <Th>Date</Th>
                    <Th>Kind</Th>
                    <Th>Type and Reason</Th>
                    <Th align="right">Points</Th>
                    {onVoid !== undefined && (
                        <Th align="right">
                            <span className="sr-only">Actions</span>
                        </Th>
                    )}
                </TableHead>
                <TableBody>
                    {pagination.rows.map((entry) => {
                        const voided = entry.voided !== null;
                        const struck = voided ? 'text-ink-muted line-through' : 'text-ink';

                        return (
                            <Tr key={entry.id}>
                                <Td className={cn('whitespace-nowrap', struck)}>{formatCalendarDate(entry.occurredOn)}</Td>
                                <Td>
                                    <StatusBadge tone={voided ? 'neutral' : entry.kind.tone}>{entry.kind.label}</StatusBadge>
                                </Td>
                                <Td className="min-w-64">
                                    <span className={cn('block font-medium', struck)}>{entry.type}</span>
                                    <span className={cn('block', struck)}>{entry.reason}</span>
                                    <span className="block text-xs text-ink-muted">
                                        Recorded by {entry.recordedBy}
                                        {entry.recordedAt !== null && `, ${formatDate.dateTime(entry.recordedAt)}`}
                                    </span>
                                    {entry.voided !== null && (
                                        <span className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink">
                                            <StatusBadge tone="neutral">Voided</StatusBadge>
                                            <span>
                                                Reason: {entry.voided.reason ?? 'Not given'}
                                                <span className="text-ink-muted">
                                                    {' '}
                                                    · {entry.voided.by ?? 'Unknown user'}, {formatDate.dateTime(entry.voided.at)}
                                                </span>
                                            </span>
                                        </span>
                                    )}
                                </Td>
                                <Td align="right" numeric className="whitespace-nowrap">
                                    <span className={cn('font-semibold', struck)}>
                                        {entry.kind.value === 'merit' ? '+' : '−'}
                                        {entry.points}
                                    </span>
                                    {voided && <span className="block text-xs font-normal text-ink-muted">Not counted</span>}
                                </Td>
                                {onVoid !== undefined && (
                                    <Td align="right">
                                        {!voided && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                icon={<Ban className="size-4" aria-hidden="true" />}
                                                aria-label={`Void the ${entry.kind.label.toLowerCase()} of ${formatPoints(entry.points)} dated ${formatCalendarDate(entry.occurredOn)}: ${entry.type}`}
                                                onClick={() => onVoid(entry)}
                                            >
                                                Void
                                            </Button>
                                        )}
                                    </Td>
                                )}
                            </Tr>
                        );
                    })}
                </TableBody>
            </Table>
            <ClientPagination pagination={pagination} noun={{ one: 'entry', other: 'entries' }} label="Merit and demerit pages" />
        </>
    );
}
