import { Head, useRemember, usePage } from '@inertiajs/react';
import { FilePenLine, Hourglass, Lock, Pencil, Trash2 } from 'lucide-react';
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
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatCalendarDate, formatPercent, useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { AssessmentCorrections, GradeCorrectionSummary } from '@/types/grade-corrections';
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
    /** Correction requests (finalized assessments only). */
    corrections: AssessmentCorrections;
    /** May create, edit, and record scores for this subject. */
    can: { manage: boolean };
}

export default function AssessmentShow({ offering, assessment, roster, history, corrections, can }: AssessmentShowProps) {
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
                                            Scores recorded: <strong className="text-ink">{scored}</strong> of {gradable.length}.
                                        </p>
                                        {withoutScore > 0 && (
                                            <p>
                                                {withoutScore} {withoutScore === 1 ? 'candidate' : 'candidates'} without a score will show as Missing.
                                            </p>
                                        )}
                                        <p>
                                            Finalized scores count toward grades and change only through an approved correction request. This
                                            cannot be undone.
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
                        Scores do not count toward grades until finalized.
                        {can.manage && unsavedCount > 0 && ' Save or discard changes before finalizing.'}
                        {can.manage && unsavedCount === 0 && scored === 0 && ' Record at least one score before finalizing.'}
                    </Alert>
                ) : (
                    <Alert tone="success" title="Finalized">
                        Scores count toward grades
                        {assessment.finalizedBy && (
                            <>
                                {' '}
                                since {formatDate.dateTime(assessment.finalizedAt)} ({assessment.finalizedBy})
                            </>
                        )}
                        . To change a score, use Request Correction; an administrator must approve it.
                    </Alert>
                )}

                {assessment.sourceExaminationId !== null && <Alert tone="info" title="Posted Examination Results">From examination #{assessment.sourceExaminationId} ({assessment.examAttemptRule} submitted attempt). Regrading the examination later does not change these scores.</Alert>}
                <Panel title="Details">
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Detail label="Component">
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
                    description={isDraft ? 'Enter raw scores.' : undefined}
                    bodyClassName="p-0"
                >
                    {isDraft && can.manage ? (
                        <ScoreSheet assessmentId={assessment.id} maxScore={assessment.maxScore} roster={roster} edits={edits} onEditsChange={setEdits} />
                    ) : (
                        <ScoreTable
                            roster={roster}
                            maxScore={assessment.maxScore}
                            finalized={!isDraft}
                            pending={corrections.pending}
                            onCorrect={!isDraft && can.manage ? (row) => setCorrectingId(row.candidate.id) : null}
                        />
                    )}
                </Panel>

                {!isDraft && corrections.recent.length > 0 && (
                    <Panel title="Correction Requests" bodyClassName="p-0">
                        <CorrectionRequests requests={corrections.recent} />
                    </Panel>
                )}

                <Panel title="Change History" description="Newest first." bodyClassName="p-0">
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
                                        Deletes {assessment.title}
                                        {recordedTotal > 0 && (
                                            <>
                                                {' '}
                                                and {recordedTotal === 1 ? 'its 1 recorded entry' : `its ${recordedTotal} recorded entries`}
                                            </>
                                        )}
                                        . This cannot be undone.
                                    </p>
                                </>
                            }
                            confirmLabel="Delete Assessment"
                        >
                            Delete Assessment
                        </ConfirmAction>
                    </div>
                )}
            </div>

            <CorrectionDialog
                assessmentId={assessment.id}
                maxScore={assessment.maxScore}
                incidentTypes={corrections.incidentTypes}
                row={correctingRow}
                onClose={() => setCorrectingId(null)}
            />
        </>
    );
}

interface ScoreTableProps {
    roster: RosterRow[];
    maxScore: string;
    /** In finalized assessments an unscored candidate counts as missing. */
    finalized: boolean;
    /** Candidate id => request waiting for approval. */
    pending: Record<string, number>;
    /** Present when corrections of finalized scores may be requested. */
    onCorrect: ((row: RosterRow) => void) | null;
}

/** Read-only scores, with a Request Correction action per row for finalized assessments. */
function ScoreTable({ roster, maxScore, finalized, pending, onCorrect }: ScoreTableProps) {
    const pagination = useClientPagination(roster);

    if (roster.length === 0) {
        return <p className="px-5 py-6 text-sm text-ink-muted">No candidates in this class.</p>;
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
                                    {row.gradable && <CorrectionAction row={row} pendingId={pending[row.candidate.id]} onCorrect={onCorrect} />}
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

/** A link to the request waiting for approval, or the button to file one. */
function CorrectionAction({ row, pendingId, onCorrect }: { row: RosterRow; pendingId: number | undefined; onCorrect: (row: RosterRow) => void }) {
    if (pendingId !== undefined) {
        return (
            <RowAction href={routes.gradeCorrections.show(pendingId)} label={`Pending correction for ${row.candidate.name}`}>
                <span className="inline-flex items-center gap-1.5">
                    <Hourglass className="size-4" aria-hidden="true" />
                    Pending
                </span>
            </RowAction>
        );
    }

    return (
        <Button
            variant="ghost"
            size="sm"
            icon={<FilePenLine className="size-4" aria-hidden="true" />}
            aria-label={`Request correction for ${row.candidate.name}`}
            onClick={() => onCorrect(row)}
        >
            Request Correction
        </Button>
    );
}

/** The latest correction requests of this assessment, each linking to its incident report. */
function CorrectionRequests({ requests }: { requests: GradeCorrectionSummary[] }) {
    const formatDate = useDateFormatter();

    return (
        <Table caption="Correction requests" className="min-w-[44rem]">
            <TableHead>
                <Th>Filed</Th>
                <Th>Candidate</Th>
                <Th align="right">Score Change</Th>
                <Th>Status</Th>
                <Th align="right">
                    <span className="sr-only">Actions</span>
                </Th>
            </TableHead>
            <TableBody>
                {requests.map((request) => (
                    <Tr key={request.id}>
                        <Td className="whitespace-nowrap text-ink">
                            {formatDate.dateTime(request.requestedAt)}
                            <span className="block text-xs text-ink-muted">{request.requestedBy}</span>
                        </Td>
                        <Td className="text-ink">
                            {request.candidate.name}
                            <span className="block text-xs text-ink-muted">{request.candidate.number}</span>
                        </Td>
                        <Td align="right" numeric className="text-ink">
                            {request.currentScore ?? 'None'} → <strong>{request.proposedScore ?? 'None'}</strong>
                        </Td>
                        <Td>
                            <StatusBadge tone={request.status.tone}>{request.status.label}</StatusBadge>
                        </Td>
                        <Td align="right">
                            <RowAction href={routes.gradeCorrections.show(request.id)} label={`Open correction request #${request.id}`}>
                                Open
                            </RowAction>
                        </Td>
                    </Tr>
                ))}
            </TableBody>
        </Table>
    );
}
