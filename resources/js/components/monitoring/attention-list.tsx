import { StandingBadge } from '@/components/grading/standing';
import { RowAction } from '@/components/ui/table';
import { formatGrade } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { MonitoredCandidateSummary } from '@/types/monitoring';

interface AttentionListProps {
    candidates: MonitoredCandidateSummary[];
    /** Offer the gradebook of the most serious subject (instructors who teach it). */
    showGradebook?: boolean;
}

/**
 * Candidates requiring attention on a dashboard: candidate number first, then
 * name, class, standing, and the most serious subject in factual wording
 * (UI_UX_DESIGN.md §35, §71, §98). No comments or individual scores.
 */
export function AttentionList({ candidates, showGradebook = false }: AttentionListProps) {
    return (
        <ul className="divide-y divide-line">
            {candidates.map((entry) => {
                const concern = entry.mostSerious;

                return (
                    <li key={entry.candidate.id} className="flex flex-wrap items-start justify-between gap-3 px-5 py-3">
                        <div className="min-w-0">
                            <p className="font-medium text-ink">
                                <span className="tabular-nums">{entry.candidate.candidateNumber}</span> · {entry.candidate.name}
                            </p>
                            <p className="text-sm text-ink-muted">
                                {entry.classBatch.name}
                                {entry.candidate.status !== null && ` · ${entry.candidate.status}`}
                            </p>
                            <p className="mt-1 flex flex-wrap items-center gap-2 text-sm text-ink">
                                <StandingBadge standing={entry.standing} />
                                {concern !== null && (
                                    <span>
                                        {concern.subject}
                                        {concern.grade !== null && <span className="tabular-nums"> · {formatGrade(concern.grade)}</span>}
                                        {concern.isProvisional && ' · in progress'}
                                    </span>
                                )}
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-1">
                            {showGradebook && concern?.canOpenGradebook && (
                                <RowAction
                                    href={routes.teaching.gradebook(entry.classBatch.id, concern.classSubjectId)}
                                    label={`Gradebook for ${concern.subject}, ${entry.classBatch.name}`}
                                >
                                    Gradebook
                                </RowAction>
                            )}
                            <RowAction href={routes.candidates.show(entry.candidate.id)} label={`View ${entry.candidate.name}`}>
                                View
                            </RowAction>
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}
