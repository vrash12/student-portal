import { ClipboardCheck } from 'lucide-react';
import { ConductTotalsList, formatPoints } from '@/components/conduct/conduct-totals';
import { ClientPagination, useClientPagination } from '@/components/ui/client-pagination';
import { EmptyState } from '@/components/ui/empty-state';
import { Panel } from '@/components/ui/panel';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatCalendarDate } from '@/lib/format';
import type { OwnConduct } from '@/types/candidate-performance';

/**
 * The candidate's own merits and demerits in the portal: the totals and
 * every entry that counts, newest first, with the reason. Voided entries
 * and staff names are never sent to the portal.
 */
export function OwnConductPanel({ conduct }: { conduct: OwnConduct }) {
    const pagination = useClientPagination(conduct.entries);

    return (
        <Panel title="Merits and Demerits" description="Points recorded by your instructors and officers. Ask the academic office about any entry." bodyClassName="p-0">
            <div className="p-5">
                <ConductTotalsList totals={conduct.totals} />
            </div>
            {conduct.entries.length === 0 ? (
                <div className="border-t border-line">
                    <EmptyState icon={ClipboardCheck} headingLevel="h3" title="No merits or demerits yet" description="Merits and demerits appear here once they are recorded." />
                </div>
            ) : (
                <div className="border-t border-line">
                    <ul className="divide-y divide-line" aria-label="Your merits and demerits">
                        {pagination.rows.map((entry) => (
                            <li key={entry.id} className="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <StatusBadge tone={entry.kind.tone}>{entry.kind.label}</StatusBadge>
                                        <span className="font-medium text-ink">{entry.type}</span>
                                    </div>
                                    <p className="mt-1 text-sm text-ink">{entry.reason}</p>
                                    <p className="mt-0.5 text-xs text-ink-muted">{formatCalendarDate(entry.occurredOn)}</p>
                                </div>
                                <p className="text-right font-semibold text-ink tabular-nums">
                                    <span aria-hidden="true">{entry.kind.value === 'merit' ? '+' : '−'}</span>
                                    <span className="sr-only">{entry.kind.value === 'merit' ? 'Plus ' : 'Minus '}</span>
                                    {formatPoints(entry.points)}
                                </p>
                            </li>
                        ))}
                    </ul>
                    <ClientPagination pagination={pagination} noun={{ one: 'entry', other: 'entries' }} label="Merit and demerit pages" />
                </div>
            )}
        </Panel>
    );
}
