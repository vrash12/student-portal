import { Head, useRemember, usePage } from '@inertiajs/react';
import { Lock, Pencil, PencilLine, Trash2 } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { CorrectionDialog } from '@/components/grading/correction-dialog';
import { gradebookBreadcrumbs, OfferingDescription } from '@/components/grading/offering-context';
import { ScoreHistory, type ScoreHistoryEntry } from '@/components/grading/score-history';
import { changedRows, ScoreSheet, type RosterRow, type ScoreEdits } from '@/components/grading/score-sheet';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { ClientPagination, useClientPagination } from '@/components/ui/client-pagination';
import { ConfirmAction } from '@/components/ui/confirm-action';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatCalendarDate, formatPercent, useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { OfferingContext, StatusValue } from '@/types/grading';

interface AssessmentShowProps {
    offering: OfferingContext;
    assessment: {
        id: number;
        title: string;
        category: { id: number; name: string; weight: string };
        maxScore: string;
        assessedOn: string | null;
        status: StatusValue & { value: 'draft' | 'finalized' };
        createdBy: string;
        finalizedBy: string | null;
        finalizedAt: string | null;
        sourceExaminationId: number | null;
        examAttemptRule: string | null;
    };
    roster: RosterRow[];
    history: { entries: ScoreHistoryEntry[]; total: number };
    /** May create, edit, and record scores for this subject. */
    can: { manage: boolean };
}

export default function AssessmentShow({ offering, assessment, roster, history, can }: AssessmentShowProps) {
    const formatDate = useDateFormatter();
    const errors = usePage().props.errors as Record<string, string | undefined>;
    // Kept in the page's history state, so Back/Forward does not lose entries.
    const [edits, setEdits] = useRemember<ScoreEdits>({}, `score-sheet:${assessment.id}`);
    const [correctingId, setCorrectingId] = useState<number | null>(null);

    const isDraft = assessment.status.value === 'draft';

    // Entries cannot be saved once the assessment is finalized (for example by
    // a co-instructor); the error below explains that they were not saved.
    useEffect(() => {
        if (!isDraft) {
            setEdits((current) => (Object.keys(current).length === 0 ? current : {}));
        }
    }, [isDraft, setEdits]);
    const gradable = roster.filter((row) => row.gradable);
    const scored = gradable.filter((row) => row.score !== null).length;
    const withoutScore = gradable.length - scored;
    const recordedTotal = roster.filter((row) => row.score !== null || row.comment !== null).length;
    const unsavedCount = changedRows(roster, edits).length;
    const correctingRow = roster.find((row) => row.candidate.id === correctingId) ?? null;

    return (
        <>
            <Head title={`${assessment.title} · ${offering.subject.name}`} />

            <PageHeader
                title={assessment.title}
                description={
                    <>
                        {offering.subject.name} · <OfferingDescription offering={offering} />
                    </>
                }
                breadcrumbs={gradebookBreadcrumbs(offering, assessment.title)}
                actions={
                    can.manage &&
                    isDraft && (
                        <>
                            <ButtonLink href={routes.assessments.edit(assessment.id)} icon={<Pencil className="size-4" aria-hidden="true" />}>
                                Edit Details
                            </ButtonLink>
                            <ConfirmAction
                                href={routes.assessments.finalize(assessment.id)}
                                method="post"
                                variant="secondary"
                                size="md"
                                tone="primary"
                                disabled={unsavedCount > 0 || scored === 0}
                                icon={<Lock className="size-4" aria-hidden="true" />}
                                title={`Finalize ${assessment.title}?`}
                                description={
                                    <>
                                        <p>
                                            Scores are recorded for <strong className="text-ink">{scored}</strong> of {gradable.length}{' '}
                                            {gradable.length === 1 ? 'candidate' : 'candidates'}.
                                        </p>
                                        {withoutScore > 0 && (
                                            <p>
                                                {withoutScore === 1 ? '1 candidate has' : `${withoutScore} candidates have`} no score and will be shown as
                                                missing until a score is recorded through a correction.
                                            </p>
                                        )}
                                        <p>
                                            Finalized scores count toward grades and can only be changed through a correction with a reason. This cannot
                                            be undone.
                                        </p>
                                    </>
                                }
                                confirmLabel="Finalize Assessment"
                            >
                                Finalize Assessment
                            </ConfirmAction>
                        </>
                    )
                }
            />

            <div className="flex flex-col gap-6">
                {/* Shown here, not in the score sheet, because the sheet is gone once the assessment is finalized. */}
                {errors.assessment !== undefined && (
                    <Alert tone="danger" title="The assessment was not changed">
                        {errors.assessment}
                    </Alert>
                )}
                {!isDraft && errors.entries !== undefined && (
                    <Alert tone="danger" title="Scores were not saved">
                        {errors.entries}
                    </Alert>
                )}

                {isDraft ? (
                    <Alert tone="info" title="Draft">
                        These scores do not count toward grades until the assessment is finalized.
                        {can.manage && unsavedCount > 0 && ' Save or discard your changes before finalizing.'}
                        {can.manage && unsavedCount === 0 && scored === 0 && ' Record at least one score before finalizing.'}
                    </Alert>
                ) : (
                    <Alert tone="success" title="Finalized">
                        These scores count toward grades
                        {assessment.finalizedBy && (
                            <>
                                {' '}
                                since {formatDate.dateTime(assessment.finalizedAt)} ({assessment.finalizedBy})
                            </>
                        )}
                        . Corrections require a reason and are recorded in the change history.
                    </Alert>
                )}

                {assessment.sourceExaminationId !== null && <Alert tone="info" title="Posted Examination Results">These scores were posted from examination #{assessment.sourceExaminationId} using the {assessment.examAttemptRule} submitted attempt. Later examination regrading does not change these recorded grades. Use Correct Score with a reason for any adjustment.</Alert>}
                <Panel title="Details">
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Detail label="Status">
                            <StatusBadge tone={assessment.status.tone}>{assessment.status.label}</StatusBadge>
                        </Detail>
                        <Detail label="Grading Category">
                            {assessment.category.name} <span className="font-normal text-ink-muted">({assessment.category.weight}%)</span>
                        </Detail>
                        <Detail label="Maximum Score">
                            <span className="tabular-nums">{assessment.maxScore}</span>
                        </Detail>
                        <Detail label="Date">{assessment.assessedOn === null ? 'Not set' : formatCalendarDate(assessment.assessedOn)}</Detail>
                        <Detail label="Scores Recorded">
                            <span className="tabular-nums">
                                {scored} of {gradable.length}
                            </span>
                        </Detail>
                        <Detail label="Created By">{assessment.createdBy}</Detail>
                    </dl>
                </Panel>

                <Panel
                    title="Scores"
                    description={isDraft ? 'Enter raw scores. Percentages are calculated when the scores are saved.' : 'Finalized scores. Percentages are of the maximum score.'}
                    bodyClassName="p-0"
                >
                    {isDraft && can.manage ? (
                        <ScoreSheet assessmentId={assessment.id} maxScore={assessment.maxScore} roster={roster} edits={edits} onEditsChange={setEdits} />
                    ) : (
                        <ScoreTable
                            roster={roster}
                            maxScore={assessment.maxScore}
                            finalized={!isDraft}
                            onCorrect={!isDraft && can.manage ? (row) => setCorrectingId(row.candidate.id) : null}
                        />
                    )}
                </Panel>

                <Panel title="Change History" description="Changes after a score was first recorded, newest first." bodyClassName="p-0">
                    <ScoreHistory entries={history.entries} total={history.total} />
                </Panel>

                {can.manage && isDraft && (
                    <div className="flex justify-end">
                        <ConfirmAction
                            href={routes.assessments.destroy(assessment.id)}
                            method="delete"
                            variant="ghost"
                            size="md"
                            icon={<Trash2 className="size-4" aria-hidden="true" />}
                            title={`Delete ${assessment.title}?`}
                            description={
                                <>
                                    <p>
                                        {assessment.title} will be deleted
                                        {recordedTotal > 0 && (
                                            <>
                                                {' '}
                                                together with {recordedTotal === 1 ? 'its 1 recorded entry' : `its ${recordedTotal} recorded entries`}
                                            </>
                                        )}
                                        .
                                    </p>
                                    <p>The deletion, including any scores, is kept in the audit log. Only draft assessments can be deleted.</p>
                                </>
                            }
                            confirmLabel="Delete Assessment"
                        >
                            Delete Assessment
                        </ConfirmAction>
                    </div>
                )}
            </div>

            <CorrectionDialog assessmentId={assessment.id} maxScore={assessment.maxScore} row={correctingRow} onClose={() => setCorrectingId(null)} />
        </>
    );
}

interface ScoreTableProps {
    roster: RosterRow[];
    maxScore: string;
    /** In finalized assessments an unscored candidate counts as missing. */
    finalized: boolean;
    /** Present when finalized scores may be corrected. */
    onCorrect: ((row: RosterRow) => void) | null;
}

/** Read-only scores, with a Correct action per row for finalized assessments. */
function ScoreTable({ roster, maxScore, finalized, onCorrect }: ScoreTableProps) {
    const pagination = useClientPagination(roster);

    if (roster.length === 0) {
        return <p className="px-5 py-6 text-sm text-ink-muted">No candidates are assigned to this class.</p>;
    }

    return (
        <>
            <Table caption="Candidate scores" className="min-w-[40rem]">
                <TableHead>
                    <Th>Candidate No.</Th>
                    <Th>Name</Th>
                    <Th align="right">Score (of {maxScore})</Th>
                    <Th align="right">Percentage</Th>
                    <Th>Comment</Th>
                    {onCorrect !== null && (
                        <Th align="right">
                            <span className="sr-only">Actions</span>
                        </Th>
                    )}
                </TableHead>
                <TableBody>
                    {pagination.rows.map((row) => (
                        <Tr key={row.candidate.id}>
                            <Td className="font-medium text-ink" numeric>
                                {row.candidate.candidateNumber}
                            </Td>
                            <Td className="text-ink">
                                <span className="block">{row.candidate.name}</span>
                                {!row.gradable && <span className="text-xs text-ink-muted">No longer graded in this class</span>}
                            </Td>
                            <Td align="right" numeric className="text-ink">
                                {row.score ?? (finalized ? <span className="text-xs font-medium text-warning-fg">Missing</span> : '—')}
                            </Td>
                            <Td align="right" numeric className="text-ink">
                                {formatPercent(row.percentage)}
                            </Td>
                            <Td className="text-ink-muted">{row.comment ?? '—'}</Td>
                            {onCorrect !== null && (
                                <Td align="right">
                                    {row.gradable && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            icon={<PencilLine className="size-4" aria-hidden="true" />}
                                            aria-label={`Correct score for ${row.candidate.name}`}
                                            onClick={() => onCorrect(row)}
                                        >
                                            Correct
                                        </Button>
                                    )}
                                </Td>
                            )}
                        </Tr>
                    ))}
                </TableBody>
            </Table>
            <ClientPagination pagination={pagination} noun={{ one: 'candidate', other: 'candidates' }} label="Score pages" />
        </>
    );
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <dt className="text-sm text-ink-muted">{label}</dt>
            <dd className="mt-0.5 font-medium text-ink">{children}</dd>
        </div>
    );
}
