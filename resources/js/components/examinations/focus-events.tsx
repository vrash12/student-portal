import { EyeOff } from 'lucide-react';
import { ClientPagination, useClientPagination } from '@/components/ui/client-pagination';
import { useDateFormatter } from '@/lib/format';

export interface FocusSummary {
    count: number;
    awaySeconds: number;
    /** Set while the candidate is away from the examination screen right now. */
    awaySince: string | null;
}

export interface FocusEvent {
    leftAt: string;
    returnedAt: string | null;
    seconds: number;
    /** hidden: the page was hidden (tab/app switch, minimized); blur: another window took focus. */
    reason: 'hidden' | 'blur';
}

/** "1 min 20 s", "45 s". */
export function formatDuration(totalSeconds: number): string {
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    return minutes === 0 ? `${seconds} s` : `${minutes} min ${seconds} s`;
}

/**
 * Compact monitoring cell: how often and how long the candidate left the
 * examination screen. An indicator to follow up on, not proof of misconduct.
 */
export function FocusSummaryCell({ summary }: { summary: FocusSummary | null }) {
    if (summary === null || summary.count === 0) {
        return <span className="text-ink-muted">None</span>;
    }

    return (
        <span className="flex flex-col gap-1">
            {summary.awaySince !== null && (
                <span className="inline-flex w-fit items-center gap-1 rounded-md border border-warning-border bg-warning-bg px-2 py-0.5 text-xs font-medium text-warning-fg">
                    <EyeOff className="size-3.5" aria-hidden="true" />
                    Away now
                </span>
            )}
            <span>
                Left {summary.count} {summary.count === 1 ? 'time' : 'times'} · {formatDuration(summary.awaySeconds)}
            </span>
        </span>
    );
}

/** Full list of departures for one attempt (grading page). */
export function FocusEventList({ events }: { events: FocusEvent[] }) {
    const format = useDateFormatter();
    const total = events.reduce((sum, event) => sum + event.seconds, 0);
    const pagination = useClientPagination(events);

    return (
        <section className="mt-8" aria-labelledby="focus-events-heading">
            <h2 id="focus-events-heading" className="text-lg font-semibold">
                Left the Examination Screen
            </h2>
            <p className="mt-1 text-sm text-ink-muted">
                Recorded when the candidate switched tabs or apps, minimized the browser, or focused another window. Browsers cannot tell why, so treat this as something to follow up on, not as proof. It does not affect the score.
            </p>
            <div className="mt-3 rounded-xl border border-line bg-surface">
                {events.length === 0 ? (
                    <p className="p-5 text-ink-muted">The candidate stayed on the examination screen.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[36rem] text-left text-sm">
                            <caption className="sr-only">Times the candidate left the examination screen</caption>
                            <thead className="border-b border-line bg-surface-muted">
                                <tr>
                                    <th className="p-3">Left</th>
                                    <th className="p-3">Returned</th>
                                    <th className="p-3">Away for</th>
                                    <th className="p-3">Detected as</th>
                                </tr>
                            </thead>
                            <tbody>
                                {pagination.rows.map((event) => (
                                    <tr key={event.leftAt} className="border-b border-line last:border-0">
                                        <td className="p-3">{format.dateTime(event.leftAt)}</td>
                                        <td className="p-3">{event.returnedAt === null ? 'Did not return before the attempt ended' : format.dateTime(event.returnedAt)}</td>
                                        <td className="p-3 tabular-nums">{formatDuration(event.seconds)}</td>
                                        <td className="p-3">{event.reason === 'hidden' ? 'Screen hidden (tab or app switch)' : 'Another window focused'}</td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td className="p-3 font-medium" colSpan={2}>
                                        {events.length} {events.length === 1 ? 'time' : 'times'} in total
                                    </td>
                                    <td className="p-3 font-medium tabular-nums" colSpan={2}>
                                        {formatDuration(total)}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                )}
                <ClientPagination pagination={pagination} noun={{ one: 'departure', other: 'departures' }} label="Departure pages" />
            </div>
        </section>
    );
}
