import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatGrade, formatPercent } from '@/lib/format';
import type { CandidateSummary, SubjectGrade } from '@/types/grading';

interface GradeBreakdownDialogProps {
    subjectName: string;
    entry: { candidate: CandidateSummary; result: SubjectGrade } | null;
    onClose: () => void;
}

/**
 * How a candidate's subject grade was calculated: each category's percentage
 * and weighted score, all as calculated by the server.
 */
export function GradeBreakdownDialog({ subjectName, entry, onClose }: GradeBreakdownDialogProps) {
    return (
        <Dialog
            open={entry !== null}
            size="lg"
            title={entry === null ? 'Grade Breakdown' : `${entry.candidate.name} · Grade Breakdown`}
            description={entry === null ? undefined : `${subjectName} · Candidate ${entry.candidate.candidateNumber} · Finalized assessments only`}
            onClose={onClose}
            footer={
                <Button variant="secondary" onClick={onClose}>
                    Close
                </Button>
            }
        >
            {entry !== null && <Breakdown result={entry.result} />}
        </Dialog>
    );
}

function Breakdown({ result }: { result: SubjectGrade }) {
    return (
        <div className="flex flex-col gap-5">
            <dl className="grid gap-4 rounded-lg border border-line bg-surface-muted px-4 py-3 sm:grid-cols-3">
                <div>
                    <dt className="text-sm text-ink-muted">Current Grade</dt>
                    <dd className="mt-0.5 text-2xl font-semibold tabular-nums text-ink">{formatGrade(result.grade)}</dd>
                </div>
                <div>
                    <dt className="text-sm text-ink-muted">Status</dt>
                    <dd className="mt-1">
                        <StatusBadge tone={result.status.tone}>{result.status.label}</StatusBadge>
                    </dd>
                </div>
                <div>
                    <dt className="text-sm text-ink-muted">Weight Assessed So Far</dt>
                    <dd className="mt-0.5 font-medium tabular-nums text-ink">{result.assessedWeight}%</dd>
                </div>
            </dl>

            <div className="overflow-x-auto">
                <table className="w-full min-w-[32rem] text-left text-sm">
                    <caption className="sr-only">Grade by category</caption>
                    <thead className="bg-surface-muted text-xs font-semibold uppercase tracking-wide text-ink-muted">
                        <tr>
                            <th scope="col" className="px-3 py-2">
                                Category
                            </th>
                            <th scope="col" className="px-3 py-2 text-right">
                                Weight
                            </th>
                            <th scope="col" className="px-3 py-2 text-right">
                                Points
                            </th>
                            <th scope="col" className="px-3 py-2 text-right">
                                Percentage
                            </th>
                            <th scope="col" className="px-3 py-2 text-right">
                                Weighted Score
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {result.categories.map((category) => (
                            <tr key={category.categoryId}>
                                <th scope="row" className="px-3 py-2 font-medium text-ink">
                                    {category.name}
                                    <span className="block text-xs font-normal text-ink-muted">
                                        {category.assessmentCount === 0
                                            ? 'No finalized assessments yet'
                                            : `${category.scoredCount} of ${category.assessmentCount} scored`}
                                        {category.missingCount > 0 && ` · ${category.missingCount} missing`}
                                    </span>
                                </th>
                                <td className="px-3 py-2 text-right tabular-nums">{category.weight}%</td>
                                <td className="px-3 py-2 text-right tabular-nums">
                                    {category.scoredCount === 0 ? '—' : `${category.earned} / ${category.possible}`}
                                </td>
                                <td className="px-3 py-2 text-right tabular-nums">{formatPercent(category.percentage)}</td>
                                <td className="px-3 py-2 text-right tabular-nums">
                                    {category.weightedScore === null ? '—' : `${formatGrade(category.weightedScore)} of ${category.weight}`}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="flex flex-col gap-1 text-sm text-ink-muted">
                {result.missingScores > 0 && (
                    <p>
                        <span className="font-medium text-warning-fg">Missing scores are not counted as zero.</span> The grade is based on the
                        assessments that have a score until the missing {result.missingScores === 1 ? 'score is' : 'scores are'} recorded.
                    </p>
                )}
                {result.pendingCategories > 0 && result.grade !== null && (
                    <p>
                        The current grade is based on the {result.assessedWeight}% of the grading weight assessed so far. It becomes the final grade
                        once every category has finalized assessments.
                    </p>
                )}
            </div>
        </div>
    );
}
