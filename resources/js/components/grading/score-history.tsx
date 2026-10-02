import { History } from 'lucide-react';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { useDateFormatter } from '@/lib/format';

export interface ScoreHistoryEntry {
    id: number;
    kind: { value: 'updated' | 'corrected'; label: string };
    candidate: { candidateNumber: string; name: string };
    previousScore: string | null;
    newScore: string | null;
    comment: string | null;
    reason: string | null;
    changedBy: string;
    changedAt: string | null;
}

interface ScoreHistoryProps {
    entries: ScoreHistoryEntry[];
    total: number;
}

/**
 * Changes made after scores were first recorded, newest first
 * (AGENTS.md §36: previous value, new value, actor, time, reason).
 */
export function ScoreHistory({ entries, total }: ScoreHistoryProps) {
    const formatDate = useDateFormatter();

    if (entries.length === 0) {
        return (
            <EmptyState
                icon={History}
                headingLevel="h3"
                title="No changes yet"
                description="Edits and corrections of recorded scores appear here."
            />
        );
    }

    return (
        <>
            <ol className="divide-y divide-line">
                {entries.map((entry) => (
                    <li key={entry.id} className="flex flex-col gap-1 px-5 py-3 text-sm">
                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <StatusBadge tone={entry.kind.value === 'corrected' ? 'info' : 'neutral'}>{entry.kind.label}</StatusBadge>
                            <span className="font-medium text-ink">
                                {entry.candidate.name} <span className="font-normal text-ink-muted">({entry.candidate.candidateNumber})</span>
                            </span>
                        </div>
                        <p className="text-ink">
                            <span className="sr-only">Score changed from </span>
                            <span className="tabular-nums">{entry.previousScore ?? 'No score'}</span>
                            <span aria-hidden="true"> → </span>
                            <span className="sr-only"> to </span>
                            <span className="font-semibold tabular-nums">{entry.newScore ?? 'No score'}</span>
                            {entry.previousScore === entry.newScore && <span className="text-ink-muted"> (comment changed)</span>}
                        </p>
                        {entry.reason && (
                            <p className="text-ink">
                                <span className="text-ink-muted">Reason:</span> {entry.reason}
                            </p>
                        )}
                        {entry.comment && (
                            <p className="text-ink-muted">
                                Comment: <span className="text-ink">{entry.comment}</span>
                            </p>
                        )}
                        <p className="text-xs text-ink-subtle">
                            {entry.changedBy} · {formatDate.dateTime(entry.changedAt)}
                        </p>
                    </li>
                ))}
            </ol>
            {total > entries.length && (
                <p className="border-t border-line px-5 py-3 text-sm text-ink-muted">
                    Latest {entries.length} of {total} changes.
                </p>
            )}
        </>
    );
}
