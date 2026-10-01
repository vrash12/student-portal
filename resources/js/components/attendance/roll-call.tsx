import { Link, router, usePage } from '@inertiajs/react';
import { CheckCheck, CircleAlert, PencilLine, Save, SearchX, Undo2 } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { AttendanceStatusBadge, attendanceStatusIcons } from '@/components/attendance/attendance-status';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ClientPagination, OtherPageErrors, pagesWhere, useClientPagination, useShowFirstErrorPage } from '@/components/ui/client-pagination';
import { EmptyState } from '@/components/ui/empty-state';
import { SearchField } from '@/components/ui/filter-bar';
import { TextInput } from '@/components/ui/form-field';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { cn } from '@/lib/cn';
import { useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { AttendanceStatusOption, AttendanceStatusValue, RollCallRow } from '@/types/attendance';

/** A local, unsaved entry of one candidate. */
interface RollCallEdit {
    status: AttendanceStatusValue | null;
    remarks: string;
}

type RollCallEdits = Record<number, RollCallEdit>;

const CONNECTION_ERROR =
    'Attendance was not saved because the connection was interrupted. Your entries are still on this page. Check the connection and save again.';

const LEAVE_MESSAGE = 'You have unsaved attendance. Leave this page and discard it?';

/** The selected choice shows the status's tone, plus the radio mark, icon and label. */
const selectedClasses: Record<StatusTone, string> = {
    success: 'border-success-fg bg-success-bg text-success-fg',
    warning: 'border-warning-fg bg-warning-bg text-warning-fg',
    info: 'border-info-fg bg-info-bg text-info-fg',
    danger: 'border-danger-fg bg-danger-bg text-danger-fg',
    neutral: 'border-neutral-fg bg-neutral-bg text-neutral-fg',
};

function savedEntry(row: RollCallRow): RollCallEdit {
    return { status: row.record?.status.value ?? null, remarks: row.record?.remarks ?? '' };
}

/** The entry differs from what the server has saved now. */
function isChanged(row: RollCallRow, edit: RollCallEdit | undefined): boolean {
    if (edit === undefined) {
        return false;
    }

    const saved = savedEntry(row);

    return edit.status !== saved.status || edit.remarks.trim() !== saved.remarks;
}

interface RollCallProps {
    sessionId: number;
    rows: RollCallRow[];
    /** The statuses in order, from the server. */
    options: AttendanceStatusOption[];
    /** Every candidate links to their profile; otherwise only candidates of this class. */
    linkAllCandidates: boolean;
}

/**
 * The roll call of a session (UI_UX_DESIGN.md §22–23, §49): each candidate
 * gets large Present / Late / Excused / Absent choices and optional remarks.
 * Only changed rows are sent; nothing is shown as saved until the server
 * confirms it, and leaving with unsaved entries asks first.
 */
export function RollCall({ sessionId, rows, options, linkAllCandidates }: RollCallProps) {
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const [edits, setEdits] = useState<RollCallEdits>({});
    const [filter, setFilter] = useState('');
    const [processing, setProcessing] = useState(false);
    const [connectionError, setConnectionError] = useState<string | null>(null);

    const pending = rows.filter((row) => row.recordable && isChanged(row, edits[row.candidate.id]));
    const unrecorded = rows.filter((row) => row.recordable && row.record === null && (edits[row.candidate.id]?.status ?? null) === null);
    const hasRowError = (row: RollCallRow): boolean =>
        Object.keys(errors).some((key) => key === `entries.${row.candidate.id}` || key.startsWith(`entries.${row.candidate.id}.`));
    const rowErrorCount = rows.filter(hasRowError).length;
    const submitPath = routes.attendance.sessions.records(sessionId);

    useUnsavedAttendanceWarning(pending.length > 0 && !processing, submitPath);

    const term = filter.trim().toLowerCase();
    const visibleRows =
        term === '' ? rows : rows.filter((row) => row.candidate.candidateNumber.toLowerCase().includes(term) || row.candidate.name.toLowerCase().includes(term));

    // Only the visible rows are paged; every entry stays in `edits`, so saving still sends all changed rows.
    const pagination = useClientPagination(visibleRows);
    const errorPages = pagesWhere(visibleRows, hasRowError);
    useShowFirstErrorPage(errors, errorPages, pagination.setPage);

    const changeFilter = (value: string) => {
        setFilter(value);
        pagination.setPage(1);
    };

    const updateRow = (row: RollCallRow, patch: Partial<RollCallEdit>) => {
        setEdits((current) => ({ ...current, [row.candidate.id]: { ...(current[row.candidate.id] ?? savedEntry(row)), ...patch } }));
    };

    const markUnrecordedPresent = () => {
        setEdits((current) => {
            const next = { ...current };
            for (const row of unrecorded) {
                next[row.candidate.id] = { ...(current[row.candidate.id] ?? savedEntry(row)), status: 'present' };
            }

            return next;
        });
    };

    const save = (submitEvent: FormEvent<HTMLFormElement>) => {
        submitEvent.preventDefault();
        if (pending.length === 0 || processing) {
            return;
        }

        const entries: Record<number, { status: AttendanceStatusValue | null; remarks: string | null }> = {};
        for (const row of pending) {
            const edit = edits[row.candidate.id] as RollCallEdit;
            const remarks = edit.remarks.trim();
            // A row without a status is sent as is, so the server explains what is missing on that row.
            entries[row.candidate.id] = { status: edit.status, remarks: remarks === '' ? null : remarks };
        }

        router.put(
            submitPath,
            { entries },
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setConnectionError(null);
                },
                onFinish: () => setProcessing(false),
                // The page now shows the saved values, so the local entries are done.
                onSuccess: () => setEdits({}),
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
            <div className="flex flex-col gap-3 border-b border-line p-4 sm:flex-row sm:flex-wrap sm:items-end">
                <SearchField label="Find Candidate" placeholder="Search candidates by number or name…" value={filter} onChange={changeFilter} />
                <Button
                    variant="secondary"
                    icon={<CheckCheck className="size-4" aria-hidden="true" />}
                    disabled={unrecorded.length === 0 || processing}
                    onClick={markUnrecordedPresent}
                >
                    Mark All Unrecorded as Present
                    {unrecorded.length > 0 && <span className="tabular-nums">({unrecorded.length})</span>}
                </Button>
            </div>

            <form onSubmit={save} noValidate>
                {(connectionError !== null || errors.entries !== undefined || rowErrorCount > 0) && (
                    <div className="flex flex-col gap-3 border-b border-line p-4">
                        {connectionError !== null && (
                            <Alert tone="danger" title="Attendance was not saved">
                                {connectionError}
                            </Alert>
                        )}
                        {errors.entries !== undefined && (
                            <Alert tone="danger" title="Attendance was not saved">
                                {errors.entries}
                            </Alert>
                        )}
                        {rowErrorCount > 0 && (
                            <Alert tone="danger" title="Attendance was not saved">
                                {rowErrorCount === 1 ? '1 candidate needs' : `${rowErrorCount} candidates need`} attention. Your entries are still on this page;
                                correct the highlighted rows and save again.
                            </Alert>
                        )}
                    </div>
                )}

                {visibleRows.length === 0 ? (
                    <EmptyState
                        icon={SearchX}
                        headingLevel="h3"
                        title={rows.length === 0 ? 'No candidates in this class' : 'No candidates match this search'}
                        description={rows.length === 0 ? 'Candidates appear here once they are assigned to the class.' : 'Try a different number or name.'}
                    />
                ) : (
                    <ul className="divide-y divide-line" aria-label="Roll call">
                        {pagination.rows.map((row) => (
                            <RollCallItem
                                key={row.candidate.id}
                                row={row}
                                edit={edits[row.candidate.id]}
                                options={options}
                                errors={errors}
                                linked={linkAllCandidates || row.inClass}
                                disabled={processing}
                                onChange={(patch) => updateRow(row, patch)}
                            />
                        ))}
                    </ul>
                )}
                <OtherPageErrors pagination={pagination} errorPages={errorPages} />
                <ClientPagination pagination={pagination} noun={{ one: 'candidate', other: 'candidates' }} label="Roll call pages" />

                <div className="sticky bottom-0 z-10 flex flex-col gap-3 rounded-b-xl border-t border-line bg-surface px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-sm text-ink-muted" role="status">
                        {pending.length === 0 ? (
                            'All changes are saved.'
                        ) : (
                            <span className="font-medium text-ink">{pending.length === 1 ? '1 unsaved change' : `${pending.length} unsaved changes`}</span>
                        )}
                    </p>
                    <div className="flex flex-col-reverse gap-2 sm:flex-row">
                        <Button
                            variant="secondary"
                            icon={<Undo2 className="size-4" aria-hidden="true" />}
                            disabled={pending.length === 0 || processing}
                            onClick={() => setEdits({})}
                        >
                            Discard Changes
                        </Button>
                        <Button type="submit" loading={processing} disabled={pending.length === 0} icon={<Save className="size-4" aria-hidden="true" />}>
                            Save Attendance
                        </Button>
                    </div>
                </div>
            </form>
        </>
    );
}

interface RollCallItemProps {
    row: RollCallRow;
    edit: RollCallEdit | undefined;
    options: AttendanceStatusOption[];
    errors: Record<string, string | undefined>;
    /** The name links to the candidate's profile. */
    linked: boolean;
    disabled: boolean;
    onChange: (patch: Partial<RollCallEdit>) => void;
}

function RollCallItem({ row, edit, options, errors, linked, disabled, onChange }: RollCallItemProps) {
    const { candidate, record } = row;
    const { dateTime } = useDateFormatter();
    const changed = row.recordable && isChanged(row, edit);
    const current = edit ?? savedEntry(row);
    const rowErrors = [errors[`entries.${candidate.id}`], errors[`entries.${candidate.id}.status`], errors[`entries.${candidate.id}.remarks`]].filter(
        (message): message is string => message !== undefined,
    );
    const errorId = `attendance-error-${candidate.id}`;

    return (
        <li className={cn('flex flex-col gap-3 px-4 py-4 lg:flex-row lg:items-start lg:gap-6', rowErrors.length > 0 ? 'bg-danger-bg/50' : changed && 'bg-info-bg/50')}>
            <div className="min-w-0 lg:w-64 lg:shrink-0">
                {linked ? (
                    <Link href={routes.candidates.show(candidate.id)} className="font-medium text-primary-700 underline">
                        {candidate.name}
                    </Link>
                ) : (
                    <span className="font-medium text-ink">{candidate.name}</span>
                )}
                <span className="block text-xs text-ink-muted tabular-nums">{candidate.candidateNumber}</span>
                {!row.recordable ? (
                    <span className="block text-xs text-ink-muted">No longer in this class · {candidate.status.label}</span>
                ) : (
                    candidate.status.value !== 'enrolled' && (
                        <StatusBadge tone={candidate.status.tone} className="mt-1">
                            {candidate.status.label}
                        </StatusBadge>
                    )
                )}
                <div className="mt-2 flex flex-wrap items-center gap-2">
                    {changed ? (
                        <span className="inline-flex items-center gap-1 text-xs font-medium text-info-fg">
                            <PencilLine className="size-3.5" aria-hidden="true" />
                            Unsaved
                        </span>
                    ) : (
                        <AttendanceStatusBadge status={record?.status ?? null} />
                    )}
                    {!changed && record !== null && record.recordedBy !== null && (
                        <span className="text-xs text-ink-subtle">
                            by {record.recordedBy}
                            {record.recordedAt !== null && <>, {dateTime(record.recordedAt)}</>}
                        </span>
                    )}
                </div>
            </div>

            <div className="flex min-w-0 flex-1 flex-col gap-2">
                {row.recordable ? (
                    <>
                        <fieldset disabled={disabled} aria-describedby={rowErrors.length > 0 ? errorId : undefined}>
                            <legend className="sr-only">
                                Attendance of {candidate.name} ({candidate.candidateNumber})
                            </legend>
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                {options.map((option) => {
                                    const checked = current.status === option.value;
                                    const Icon = attendanceStatusIcons[option.value];

                                    return (
                                        <label
                                            key={option.value}
                                            className={cn(
                                                'flex min-h-12 cursor-pointer select-none items-center justify-center gap-2 rounded-lg border-2 px-3 py-2 text-sm font-semibold transition-colors has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary-600',
                                                checked ? selectedClasses[option.tone] : 'border-line-strong bg-surface text-ink hover:bg-surface-muted',
                                                disabled && 'cursor-not-allowed opacity-60',
                                            )}
                                        >
                                            <input
                                                type="radio"
                                                name={`attendance-${candidate.id}`}
                                                value={option.value}
                                                checked={checked}
                                                onChange={() => onChange({ status: option.value })}
                                                className="size-4 shrink-0 accent-primary-600 focus-visible:outline-none"
                                            />
                                            <Icon className="size-4 shrink-0" aria-hidden="true" />
                                            <span>{option.label}</span>
                                        </label>
                                    );
                                })}
                            </div>
                        </fieldset>
                        <TextInput
                            value={current.remarks}
                            onChange={(event) => onChange({ remarks: event.target.value })}
                            maxLength={255}
                            autoComplete="off"
                            disabled={disabled}
                            placeholder="Remarks (optional)"
                            aria-label={`Remarks for ${candidate.name}`}
                            aria-invalid={errors[`entries.${candidate.id}.remarks`] !== undefined || undefined}
                            aria-describedby={rowErrors.length > 0 ? errorId : undefined}
                        />
                        {rowErrors.length > 0 && (
                            <div id={errorId} className="flex flex-col gap-1">
                                {rowErrors.map((message) => (
                                    <p key={message} className="flex items-start gap-1.5 text-sm text-danger-fg">
                                        <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                        <span>{message}</span>
                                    </p>
                                ))}
                            </div>
                        )}
                    </>
                ) : (
                    <p className="text-sm text-ink-muted">{record?.remarks ?? 'No remarks.'}</p>
                )}
            </div>
        </li>
    );
}

/**
 * While `active`, asks before leaving the page through a link or another
 * action, a reload, or closing the tab. Saving the roll call itself
 * (`submitPath`) is never interrupted.
 */
function useUnsavedAttendanceWarning(active: boolean, submitPath: string) {
    useEffect(() => {
        if (!active) {
            return;
        }

        const onBeforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
        };
        window.addEventListener('beforeunload', onBeforeUnload);

        const removeGuard = router.on('before', (event) => {
            const { visit } = event.detail;
            const isSave = visit.method === 'put' && visit.url.pathname === submitPath;

            if (!isSave && !window.confirm(LEAVE_MESSAGE)) {
                event.preventDefault();
            }
        });

        return () => {
            window.removeEventListener('beforeunload', onBeforeUnload);
            removeGuard();
        };
    }, [active, submitPath]);
}
