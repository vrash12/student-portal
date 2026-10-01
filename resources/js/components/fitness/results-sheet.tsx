import { Link, router, usePage } from '@inertiajs/react';
import { CircleAlert, PencilLine, Save, SearchX, Undo2 } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ClientPagination, OtherPageErrors, pagesWhere, useClientPagination, useShowFirstErrorPage } from '@/components/ui/client-pagination';
import { EmptyState } from '@/components/ui/empty-state';
import { SearchField } from '@/components/ui/filter-bar';
import { TextInput } from '@/components/ui/form-field';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatGrade, formatPoints } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { FitnessEventResult, FitnessSheetRow, FitnessTestEvent } from '@/types/fitness';

/** Local, unsaved entries: candidate id → test event id → typed value. */
type Edits = Record<number, Record<number, string>>;

const CONNECTION_ERROR =
    'Results were not saved because the connection was interrupted. Your entries are still on this page. Check the connection and save again.';

interface ResultsSheetProps {
    testId: number;
    events: FitnessTestEvent[];
    rows: FitnessSheetRow[];
    /** May record results; otherwise the sheet is read-only. */
    editable: boolean;
    /** Candidate names link to their profiles. */
    linkCandidates: boolean;
}

function savedText(result: FitnessEventResult | null | undefined): string {
    return result?.display ?? '';
}

/**
 * Raw results of a fitness test for the whole class. Only changed cells are
 * sent; points, pass/fail and the overall result are calculated by the
 * server and shown once saved. Nothing is shown as saved before the server
 * confirms it.
 */
export function ResultsSheet({ testId, events, rows, editable, linkCandidates }: ResultsSheetProps) {
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const [edits, setEdits] = useState<Edits>({});
    const [filter, setFilter] = useState('');
    const [processing, setProcessing] = useState(false);
    const [connectionError, setConnectionError] = useState<string | null>(null);

    const changes: Array<{ candidateId: number; eventId: number; value: string }> = [];
    for (const row of rows) {
        for (const event of events) {
            const typed = edits[row.candidate.id]?.[event.id];
            if (row.recordable && typed !== undefined && typed.trim() !== savedText(row.results[event.id])) {
                changes.push({ candidateId: row.candidate.id, eventId: event.id, value: typed.trim() });
            }
        }
    }
    const errorCount = Object.keys(errors).filter((key) => key.startsWith('entries.')).length;

    useUnsavedResultsWarning(changes.length > 0 && !processing);

    const term = filter.trim().toLowerCase();
    const visibleRows =
        term === '' ? rows : rows.filter((row) => row.candidate.candidateNumber.toLowerCase().includes(term) || row.candidate.name.toLowerCase().includes(term));

    // Only the visible rows are paged; every entry stays in `edits`, so saving still sends all changed cells.
    const pagination = useClientPagination(visibleRows);
    const errorKeys = Object.keys(errors);
    const errorPages = pagesWhere(visibleRows, (row) => errorKeys.some((key) => key === `entries.${row.candidate.id}` || key.startsWith(`entries.${row.candidate.id}.`)));
    useShowFirstErrorPage(errors, errorPages, pagination.setPage);

    const changeFilter = (value: string) => {
        setFilter(value);
        pagination.setPage(1);
    };

    const setCell = (candidateId: number, eventId: number, value: string) => {
        setEdits((current) => ({ ...current, [candidateId]: { ...current[candidateId], [eventId]: value } }));
    };

    const save = (submitEvent: FormEvent<HTMLFormElement>) => {
        submitEvent.preventDefault();
        if (changes.length === 0 || processing) {
            return;
        }

        const entries: Record<number, Record<number, string>> = {};
        for (const change of changes) {
            entries[change.candidateId] = { ...entries[change.candidateId], [change.eventId]: change.value };
        }

        router.put(
            routes.fitness.tests.results(testId),
            { entries },
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setConnectionError(null);
                },
                onFinish: () => setProcessing(false),
                onSuccess: () => setEdits({}),
                onNetworkError: () => {
                    setConnectionError(CONNECTION_ERROR);

                    return false;
                },
            },
        );
    };

    const table =
        visibleRows.length === 0 ? (
            <EmptyState
                icon={SearchX}
                headingLevel="h3"
                title={rows.length === 0 ? 'No candidates in this class' : 'No candidates match this search'}
                description={rows.length === 0 ? 'Candidates appear here once they are assigned to the class.' : 'Try a different number or name.'}
            />
        ) : (
            <>
                <Table caption="Fitness results" className="min-w-[40rem]">
                    <TableHead>
                        <Th>Candidate</Th>
                        {events.map((event) => (
                            <Th key={event.id}>
                                {event.name}
                                <span className="block font-normal normal-case tracking-normal">
                                    Pass {event.passingDisplay} ({formatPoints(event.passingPoints)} pts) · Best {event.maximumDisplay}
                                </span>
                            </Th>
                        ))}
                        <Th>Overall</Th>
                    </TableHead>
                    <TableBody>
                        {pagination.rows.map((row) => (
                            <Tr key={row.candidate.id}>
                                <Td className="text-ink">
                                    {linkCandidates ? (
                                        <Link href={routes.candidates.show(row.candidate.id)} className="block font-medium text-primary-700 underline">
                                            {row.candidate.name}
                                        </Link>
                                    ) : (
                                        <span className="block font-medium">{row.candidate.name}</span>
                                    )}
                                    <span className="text-xs text-ink-muted tabular-nums">{row.candidate.candidateNumber}</span>
                                    {!row.recordable && <span className="block text-xs text-ink-muted">No longer in this class · {row.candidate.status.label}</span>}
                                </Td>
                                {events.map((event) => {
                                    const saved = row.results[event.id] ?? null;
                                    const typed = edits[row.candidate.id]?.[event.id];
                                    const changed = typed !== undefined && typed.trim() !== savedText(saved);
                                    const error = errors[`entries.${row.candidate.id}.${event.id}`];
                                    const errorId = `fitness-error-${row.candidate.id}-${event.id}`;

                                    return (
                                        <Td key={event.id}>
                                            <div className="flex flex-col gap-1">
                                                {editable && row.recordable ? (
                                                    <TextInput
                                                        value={typed ?? savedText(saved)}
                                                        onChange={(changeEvent) => setCell(row.candidate.id, event.id, changeEvent.target.value)}
                                                        inputMode={event.unit === 'time' ? 'text' : 'numeric'}
                                                        placeholder={event.unit === 'time' ? 'mm:ss' : undefined}
                                                        autoComplete="off"
                                                        disabled={processing}
                                                        aria-label={`${event.name} for ${row.candidate.name}`}
                                                        aria-invalid={error !== undefined || undefined}
                                                        aria-describedby={error !== undefined ? errorId : undefined}
                                                        className="w-24 tabular-nums aria-invalid:border-danger-fg"
                                                    />
                                                ) : (
                                                    <span className="font-medium text-ink tabular-nums">{saved?.display ?? '—'}</span>
                                                )}
                                                {error !== undefined && (
                                                    <p id={errorId} className="flex max-w-48 items-start gap-1 text-xs text-danger-fg">
                                                        <CircleAlert className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                                                        <span>{error}</span>
                                                    </p>
                                                )}
                                                {changed ? (
                                                    <span className="inline-flex items-center gap-1 text-xs font-medium text-info-fg">
                                                        <PencilLine className="size-3.5" aria-hidden="true" />
                                                        Unsaved
                                                    </span>
                                                ) : (
                                                    saved !== null && <EventScore result={saved} />
                                                )}
                                            </div>
                                        </Td>
                                    );
                                })}
                                <Td>
                                    <StatusBadge tone={row.outcome.status.tone as StatusTone}>{row.outcome.status.label}</StatusBadge>
                                    {row.outcome.points !== null && (
                                        <span className="mt-1 block text-sm font-semibold text-ink tabular-nums">{formatGrade(row.outcome.points)} pts</span>
                                    )}
                                </Td>
                            </Tr>
                        ))}
                    </TableBody>
                </Table>
                <OtherPageErrors pagination={pagination} errorPages={errorPages} />
                <ClientPagination pagination={pagination} noun={{ one: 'candidate', other: 'candidates' }} label="Fitness result pages" />
            </>
        );

    return (
        <>
            {/* The search sits outside the form so Enter never saves. */}
            <div className="border-b border-line p-4">
                <SearchField label="Find Candidate" placeholder="Search candidates by number or name…" value={filter} onChange={changeFilter} />
            </div>

            {!editable ? (
                table
            ) : (
                <form onSubmit={save} noValidate>
                    {(connectionError !== null || errors.entries !== undefined || errorCount > 0) && (
                        <div className="flex flex-col gap-3 border-b border-line p-4">
                            {connectionError !== null && (
                                <Alert tone="danger" title="Results were not saved">
                                    {connectionError}
                                </Alert>
                            )}
                            {errors.entries !== undefined && (
                                <Alert tone="danger" title="Results were not saved">
                                    {errors.entries}
                                </Alert>
                            )}
                            {errorCount > 0 && (
                                <Alert tone="danger" title="Results were not saved">
                                    {errorCount === 1 ? '1 entry needs' : `${errorCount} entries need`} attention. Your entries are still on this page; correct the
                                    highlighted cells and save again.
                                </Alert>
                            )}
                        </div>
                    )}

                    {table}

                    <div className="sticky bottom-0 z-10 flex flex-col gap-3 rounded-b-lg border-t border-line bg-surface px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-sm text-ink-muted" role="status">
                            {changes.length === 0 ? (
                                'All changes are saved.'
                            ) : (
                                <span className="font-medium text-ink">{changes.length === 1 ? '1 unsaved change' : `${changes.length} unsaved changes`}</span>
                            )}
                        </p>
                        <div className="flex flex-col-reverse gap-2 sm:flex-row">
                            <Button
                                variant="secondary"
                                icon={<Undo2 className="size-4" aria-hidden="true" />}
                                disabled={changes.length === 0 || processing}
                                onClick={() => setEdits({})}
                            >
                                Discard Changes
                            </Button>
                            <Button type="submit" loading={processing} disabled={changes.length === 0} icon={<Save className="size-4" aria-hidden="true" />}>
                                Save Results
                            </Button>
                        </div>
                    </div>
                </form>
            )}
        </>
    );
}

function EventScore({ result }: { result: FitnessEventResult }) {
    return (
        <span className={`text-xs tabular-nums ${result.passed ? 'text-ink-muted' : 'font-medium text-danger-fg'}`}>
            {formatGrade(result.points)} pts · {result.passed ? 'Pass' : 'Below standard'}
        </span>
    );
}

/** Asks before leaving the page while there are unsaved results. */
function useUnsavedResultsWarning(active: boolean) {
    useEffect(() => {
        if (!active) {
            return;
        }

        const onBeforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
        };
        window.addEventListener('beforeunload', onBeforeUnload);

        const removeGuard = router.on('before', (event) => {
            if (event.detail.visit.method === 'get' && !window.confirm('You have unsaved fitness results. Leave this page and discard them?')) {
                event.preventDefault();
            }
        });

        return () => {
            window.removeEventListener('beforeunload', onBeforeUnload);
            removeGuard();
        };
    }, [active]);
}
