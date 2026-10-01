import { router, usePage } from '@inertiajs/react';
import { CircleAlert, PencilLine, Save, SearchX, TriangleAlert, Undo2 } from 'lucide-react';
import { useEffect, useState, type Dispatch, type FormEvent, type SetStateAction } from 'react';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ClientPagination, OtherPageErrors, pagesWhere, useClientPagination, useShowFirstErrorPage } from '@/components/ui/client-pagination';
import { EmptyState } from '@/components/ui/empty-state';
import { SearchField } from '@/components/ui/filter-bar';
import { TextInput } from '@/components/ui/form-field';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatPercent } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { CandidateSummary } from '@/types/grading';

export interface RosterRow {
    candidate: CandidateSummary;
    /** Saved score in compact form ("45.5"), or null when none is recorded. */
    score: string | null;
    comment: string | null;
    /** Calculated by the server for the saved score. */
    percentage: number | null;
    /** False for candidates who left the class or withdrew; their row is read-only. */
    gradable: boolean;
}

/**
 * A local, unsaved entry. The base values are the saved values the user
 * started from; they are sent as expected values so the server can tell when
 * someone else changed the score in the meantime.
 */
export interface ScoreEdit {
    score: string;
    comment: string;
    baseScore: string | null;
    baseComment: string | null;
}

export type ScoreEdits = Record<number, ScoreEdit>;

const CONNECTION_ERROR =
    'Scores were not saved because the connection was interrupted. Your entries are still on this page. Check the connection and save again.';

/**
 * Canonical form of a typed score, used only to tell whether a row changed
 * ("45.0" and "45" are the same). Validation happens on the server.
 */
function canonicalScore(value: string | null): string {
    const trimmed = (value ?? '').trim();
    if (trimmed === '') {
        return '';
    }

    const number = Number(trimmed);

    return Number.isFinite(number) ? String(number) : trimmed;
}

function sameValues(score: string | null, comment: string | null, row: RosterRow): boolean {
    return canonicalScore(score) === canonicalScore(row.score) && (comment ?? '').trim() === (row.comment ?? '');
}

/** The entry differs from the value saved on the server now. */
export function isRowChanged(row: RosterRow, edit: ScoreEdit | undefined): boolean {
    return edit !== undefined && !sameValues(edit.score, edit.comment, row);
}

/** Someone else saved a different value after this entry was started. */
function isRowStale(row: RosterRow, edit: ScoreEdit | undefined): boolean {
    return edit !== undefined && !sameValues(edit.baseScore, edit.baseComment, row);
}

export function changedRows(roster: RosterRow[], edits: ScoreEdits): RosterRow[] {
    return roster.filter((row) => row.gradable && isRowChanged(row, edits[row.candidate.id]));
}

function nullIfBlank(value: string): string | null {
    const trimmed = value.trim();

    return trimmed === '' ? null : trimmed;
}

interface ScoreSheetProps {
    assessmentId: number;
    maxScore: string;
    roster: RosterRow[];
    edits: ScoreEdits;
    onEditsChange: Dispatch<SetStateAction<ScoreEdits>>;
}

/**
 * Draft score entry for a whole class (AGENTS.md §14, §38; UI_UX_DESIGN.md
 * §31, §49). Only changed rows are sent, each with the value it was based
 * on; a row that someone else changed meanwhile must be resolved explicitly
 * before saving. Nothing is shown as saved until the server confirms it.
 */
export function ScoreSheet({ assessmentId, maxScore, roster, edits, onEditsChange }: ScoreSheetProps) {
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const [processing, setProcessing] = useState(false);
    const [filter, setFilter] = useState('');
    const [connectionError, setConnectionError] = useState<string | null>(null);
    const [discarded, setDiscarded] = useState<string[]>([]);

    // Entries for candidates who can no longer be graded here (moved or
    // withdrawn since the sheet was opened) cannot be saved; drop them and
    // say so instead of leaving a stuck "Unsaved" row.
    useEffect(() => {
        const gradableIds = new Set(roster.filter((row) => row.gradable).map((row) => row.candidate.id));
        const orphaned = Object.keys(edits)
            .map(Number)
            .filter((candidateId) => !gradableIds.has(candidateId));

        if (orphaned.length === 0) {
            return;
        }

        const names = orphaned.map((candidateId) => {
            const row = roster.find((entry) => entry.candidate.id === candidateId);

            return row === undefined ? `Candidate #${candidateId}` : `${row.candidate.name} (${row.candidate.candidateNumber})`;
        });
        setDiscarded((current) => [...current, ...names]);
        onEditsChange((current) => Object.fromEntries(Object.entries(current).filter(([candidateId]) => gradableIds.has(Number(candidateId)))));
    }, [roster, edits, onEditsChange]);

    const pending = changedRows(roster, edits);
    const stale = pending.filter((row) => isRowStale(row, edits[row.candidate.id]));
    const rowErrorCount = roster.filter((row) => row.gradable && errors[`entries.${row.candidate.id}.score`] !== undefined).length;

    useUnsavedChangesWarning(pending.length > 0 && !processing);

    const term = filter.trim().toLowerCase();
    const visibleRows =
        term === ''
            ? roster
            : roster.filter((row) => row.candidate.candidateNumber.toLowerCase().includes(term) || row.candidate.name.toLowerCase().includes(term));

    const rowError = (row: RosterRow): string | undefined => errors[`entries.${row.candidate.id}.score`] ?? errors[`entries.${row.candidate.id}.comment`];

    // Only the visible rows are paged; every entry stays in `edits`, so saving still sends all changed rows.
    const pagination = useClientPagination(visibleRows);
    const errorPages = pagesWhere(visibleRows, (row) => rowError(row) !== undefined);
    const stalePages = pagesWhere(visibleRows, (row) => row.gradable && isRowChanged(row, edits[row.candidate.id]) && isRowStale(row, edits[row.candidate.id]));
    useShowFirstErrorPage(errors, errorPages.length > 0 ? errorPages : stalePages, pagination.setPage);

    const changeFilter = (value: string) => {
        setFilter(value);
        pagination.setPage(1);
    };

    const updateRow = (row: RosterRow, patch: Partial<Pick<ScoreEdit, 'score' | 'comment'>>) => {
        onEditsChange((current) => ({
            ...current,
            [row.candidate.id]: {
                ...(current[row.candidate.id] ?? {
                    score: row.score ?? '',
                    comment: row.comment ?? '',
                    baseScore: row.score,
                    baseComment: row.comment,
                }),
                ...patch,
            },
        }));
    };

    /** Keep the local entry and replace the other user's value on the next save. */
    const keepEntry = (row: RosterRow) => {
        onEditsChange((current) => {
            const edit = current[row.candidate.id];

            return edit === undefined ? current : { ...current, [row.candidate.id]: { ...edit, baseScore: row.score, baseComment: row.comment } };
        });
    };

    /** Drop the local entry and show the value saved by the other user. */
    const acceptSavedValue = (row: RosterRow) => {
        onEditsChange((current) => Object.fromEntries(Object.entries(current).filter(([candidateId]) => Number(candidateId) !== row.candidate.id)));
    };

    const save = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (pending.length === 0 || stale.length > 0 || processing) {
            return;
        }

        const entries = Object.fromEntries(
            pending.map((row) => {
                const edit = edits[row.candidate.id] as ScoreEdit;

                return [
                    row.candidate.id,
                    {
                        score: nullIfBlank(edit.score),
                        comment: nullIfBlank(edit.comment),
                        expected_score: edit.baseScore,
                        expected_comment: edit.baseComment,
                    },
                ];
            }),
        );

        router.put(
            routes.assessments.scores(assessmentId),
            { entries },
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setConnectionError(null);
                    setDiscarded([]);
                },
                onFinish: () => setProcessing(false),
                // The page now shows the saved values, so the local entries are done.
                onSuccess: () => onEditsChange({}),
                onNetworkError: () => {
                    setConnectionError(CONNECTION_ERROR);

                    return false;
                },
            },
        );
    };

    return (
        <>
            {/* The search sits outside the form so Enter or the keyboard's Search key never saves. */}
            <div className="border-b border-line p-4">
                <SearchField label="Find Candidate" placeholder="Search candidates by number or name…" value={filter} onChange={changeFilter} />
            </div>

            <form onSubmit={save} noValidate>
                {(connectionError !== null || errors.entries !== undefined || rowErrorCount > 0 || discarded.length > 0 || stale.length > 0) && (
                    <div className="flex flex-col gap-3 border-b border-line p-4">
                        {connectionError !== null && (
                            <Alert tone="danger" title="Scores were not saved">
                                {connectionError}
                            </Alert>
                        )}
                        {errors.entries !== undefined && (
                            <Alert tone="danger" title="Scores were not saved">
                                {errors.entries}
                            </Alert>
                        )}
                        {rowErrorCount > 0 && (
                            <Alert tone="danger" title="Scores were not saved">
                                {rowErrorCount === 1 ? '1 row needs' : `${rowErrorCount} rows need`} attention. Your entries are still on this page;
                                correct the highlighted rows and save again.
                            </Alert>
                        )}
                        {stale.length > 0 && (
                            <Alert tone="warning" title="Another user changed some scores">
                                {stale.length === 1 ? '1 row was' : `${stale.length} rows were`} changed by someone else after you started editing.
                                For each highlighted row, keep your entry or use the saved value, then save.
                            </Alert>
                        )}
                        {discarded.length > 0 && (
                            <Alert tone="warning" title="Some entries could not be kept">
                                These candidates are no longer graded in this class, so their unsaved entries were removed: {discarded.join(', ')}.
                            </Alert>
                        )}
                    </div>
                )}

                {visibleRows.length === 0 ? (
                    <EmptyState
                        icon={SearchX}
                        headingLevel="h3"
                        title={roster.length === 0 ? 'No candidates to grade' : 'No candidates match this search'}
                        description={
                            roster.length === 0
                                ? 'Candidates appear here once an administrator assigns them to this class.'
                                : 'Try a different number or name.'
                        }
                    />
                ) : (
                    <Table caption="Candidate scores" className="min-w-[44rem]">
                        <TableHead>
                            <Th>Candidate No.</Th>
                            <Th>Name</Th>
                            <Th>
                                Score <span className="font-normal normal-case tracking-normal">(of {maxScore})</span>
                            </Th>
                            <Th align="right">Percentage</Th>
                            <Th>Comment</Th>
                        </TableHead>
                        <TableBody>
                            {pagination.rows.map((row) => (
                                <ScoreRow
                                    key={row.candidate.id}
                                    row={row}
                                    edit={edits[row.candidate.id]}
                                    error={rowError(row)}
                                    disabled={processing}
                                    onChange={(patch) => updateRow(row, patch)}
                                    onKeepEntry={() => keepEntry(row)}
                                    onUseSavedValue={() => acceptSavedValue(row)}
                                />
                            ))}
                        </TableBody>
                    </Table>
                )}
                <OtherPageErrors pagination={pagination} errorPages={errorPages} />
                <OtherPageErrors pagination={pagination} errorPages={stalePages} label="Changed by another user on other pages" tone="warning" />
                <ClientPagination pagination={pagination} noun={{ one: 'candidate', other: 'candidates' }} label="Score sheet pages" />

                <div className="sticky bottom-0 z-10 flex flex-col gap-3 rounded-b-lg border-t border-line bg-surface px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-sm text-ink-muted" role="status">
                        {pending.length === 0 ? (
                            'All changes are saved.'
                        ) : (
                            <span className="font-medium text-ink">
                                {pending.length === 1 ? '1 unsaved change' : `${pending.length} unsaved changes`}
                                {stale.length > 0 && ' · resolve the highlighted rows first'}
                            </span>
                        )}
                    </p>
                    <div className="flex flex-col-reverse gap-2 sm:flex-row">
                        <Button
                            variant="secondary"
                            icon={<Undo2 className="size-4" aria-hidden="true" />}
                            disabled={pending.length === 0 || processing}
                            onClick={() => onEditsChange({})}
                        >
                            Discard Changes
                        </Button>
                        <Button
                            type="submit"
                            loading={processing}
                            disabled={pending.length === 0 || stale.length > 0}
                            icon={<Save className="size-4" aria-hidden="true" />}
                        >
                            Save Scores
                        </Button>
                    </div>
                </div>
            </form>
        </>
    );
}

interface ScoreRowProps {
    row: RosterRow;
    edit: ScoreEdit | undefined;
    error: string | undefined;
    disabled: boolean;
    onChange: (patch: Partial<Pick<ScoreEdit, 'score' | 'comment'>>) => void;
    onKeepEntry: () => void;
    onUseSavedValue: () => void;
}

function ScoreRow({ row, edit, error, disabled, onChange, onKeepEntry, onUseSavedValue }: ScoreRowProps) {
    const { candidate } = row;
    const changed = row.gradable && isRowChanged(row, edit);
    const stale = changed && isRowStale(row, edit);
    const errorId = `score-error-${candidate.id}`;
    const staleId = `score-stale-${candidate.id}`;
    const describedBy = [error !== undefined ? errorId : null, stale ? staleId : null].filter(Boolean).join(' ') || undefined;

    return (
        <Tr>
            <Td className="font-medium text-ink" numeric>
                {candidate.candidateNumber}
            </Td>
            <Td className="text-ink">
                <span className="block">{candidate.name}</span>
                {!row.gradable ? (
                    <span className="text-xs text-ink-muted">No longer graded in this class · {candidate.status.label}</span>
                ) : (
                    candidate.status.value !== 'enrolled' && <StatusBadge tone={candidate.status.tone}>{candidate.status.label}</StatusBadge>
                )}
            </Td>
            <Td>
                {row.gradable ? (
                    <div className="flex flex-col gap-1">
                        <TextInput
                            value={edit?.score ?? row.score ?? ''}
                            onChange={(event) => onChange({ score: event.target.value })}
                            inputMode="decimal"
                            autoComplete="off"
                            disabled={disabled}
                            aria-label={`Score for ${candidate.name}`}
                            aria-invalid={error !== undefined || stale || undefined}
                            aria-describedby={describedBy}
                            className="w-24 tabular-nums aria-invalid:border-danger-fg"
                        />
                        {error !== undefined && (
                            <p id={errorId} className="flex max-w-60 items-start gap-1 text-xs text-danger-fg">
                                <CircleAlert className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                                <span>{error}</span>
                            </p>
                        )}
                        {stale && (
                            <div id={staleId} className="flex max-w-72 flex-col gap-1.5 text-xs text-warning-fg">
                                <p className="flex items-start gap-1">
                                    <TriangleAlert className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                                    <span>
                                        Changed by another user to <strong className="tabular-nums">{row.score ?? 'no score'}</strong>
                                        {row.comment !== null && <> (comment: {row.comment})</>}.
                                    </span>
                                </p>
                                <div className="flex flex-wrap gap-1">
                                    <Button variant="secondary" size="sm" onClick={onKeepEntry} disabled={disabled}>
                                        Keep My Entry
                                    </Button>
                                    <Button variant="ghost" size="sm" onClick={onUseSavedValue} disabled={disabled}>
                                        Use Saved Value
                                    </Button>
                                </div>
                            </div>
                        )}
                    </div>
                ) : (
                    <span className="tabular-nums text-ink">{row.score ?? '—'}</span>
                )}
            </Td>
            <Td align="right" numeric className="text-ink">
                {changed ? (
                    <span className="inline-flex items-center gap-1 whitespace-nowrap text-xs font-medium text-info-fg">
                        <PencilLine className="size-3.5" aria-hidden="true" />
                        Unsaved
                    </span>
                ) : (
                    formatPercent(row.percentage)
                )}
            </Td>
            <Td>
                {row.gradable ? (
                    <TextInput
                        value={edit?.comment ?? row.comment ?? ''}
                        onChange={(event) => onChange({ comment: event.target.value })}
                        maxLength={500}
                        autoComplete="off"
                        disabled={disabled}
                        placeholder="Optional"
                        aria-label={`Comment for ${candidate.name}`}
                        className="min-w-48"
                    />
                ) : (
                    <span className="text-ink-muted">{row.comment ?? '—'}</span>
                )}
            </Td>
        </Tr>
    );
}

/**
 * Asks before leaving the page through a link, a reload, or closing the tab
 * while there are unsaved scores. Browser Back/Forward is not interrupted;
 * the entries are kept in the page's history state instead (useRemember in
 * the assessment page), so they come back on Forward.
 */
function useUnsavedChangesWarning(active: boolean) {
    useEffect(() => {
        if (!active) {
            return;
        }

        const onBeforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
        };
        window.addEventListener('beforeunload', onBeforeUnload);

        const removeGuard = router.on('before', (event) => {
            if (event.detail.visit.method === 'get' && !window.confirm('You have unsaved scores. Leave this page and discard them?')) {
                event.preventDefault();
            }
        });

        return () => {
            window.removeEventListener('beforeunload', onBeforeUnload);
            removeGuard();
        };
    }, [active]);
}
