import { useForm } from '@inertiajs/react';
import { HeartPulse, Hourglass, LockKeyhole, Pencil } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { ConfirmAction } from '@/components/ui/confirm-action';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField, TextArea } from '@/components/ui/form-field';
import { Panel } from '@/components/ui/panel';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatCalendarDate, useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { MedicalAccessState, MedicalEntry, MedicalFieldTypeValue, ProfileMedical } from '@/types/medical';

/** Same limits as MedicalAccessService. */
const REASON_MIN = 20;
const REASON_MAX = 1000;

/** A recorded value as people read it; null when not recorded. */
export function formatMedicalValue(type: MedicalFieldTypeValue, value: string | null, unit: string | null = null): string | null {
    if (value === null || value === '') {
        return null;
    }
    if (type === 'number') {
        return unit ? `${value} ${unit}` : value;
    }
    if (type === 'yes_no') {
        return value === 'yes' ? 'Yes' : 'No';
    }
    if (type === 'date') {
        return formatCalendarDate(value);
    }

    return value;
}

/**
 * Consecutive items of the same section, in the order given (fields are
 * ordered by the administrators); items without a section form a group of their own.
 */
export function groupBySection<T extends { section: string | null }>(items: T[]): Array<{ section: string | null; items: T[] }> {
    const groups: Array<{ section: string | null; items: T[] }> = [];
    for (const item of items) {
        const last = groups[groups.length - 1];
        if (last !== undefined && last.section === item.section) {
            last.items.push(item);
        } else {
            groups.push({ section: item.section, items: [item] });
        }
    }

    return groups;
}

/** Fields and values of a medical record, by section, two columns on wide screens. */
export function MedicalEntries({ entries, showAudience = false }: { entries: MedicalEntry[]; showAudience?: boolean }) {
    return (
        <div className="flex flex-col gap-6">
            {groupBySection(entries).map((group, index) => (
                <section key={`${group.section ?? 'general'}-${index}`} aria-label={group.section ?? 'Medical record'} className="flex flex-col gap-3">
                    {group.section !== null && (
                        <h3 className="border-b border-line pb-1.5 text-xs font-semibold uppercase tracking-wider text-primary-800">{group.section}</h3>
                    )}
                    <EntryList entries={group.items} showAudience={showAudience} />
                </section>
            ))}
        </div>
    );
}

function EntryList({ entries, showAudience }: { entries: MedicalEntry[]; showAudience: boolean }) {
    return (
        <dl className="grid gap-x-8 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
            {entries.map((entry) => {
                const shown = formatMedicalValue(entry.type, entry.value, entry.unit);

                return (
                    <div key={entry.fieldId} className={entry.type === 'long_text' ? 'sm:col-span-2 lg:col-span-3' : undefined}>
                        <dt className="flex flex-wrap items-center gap-2 text-sm text-ink-muted">
                            {entry.name}
                            {showAudience && <Audience candidate={entry.visibleToCandidate} />}
                        </dt>
                        <dd className={shown === null ? 'mt-0.5 text-ink-muted italic' : 'mt-0.5 whitespace-pre-line font-medium text-ink'}>
                            {shown ?? 'Not recorded'}
                        </dd>
                    </div>
                );
            })}
        </dl>
    );
}

/** Marks, for medical staff, a field the candidate does not see. Always text, never colour alone. */
export function Audience({ candidate }: { candidate: boolean }) {
    if (candidate) {
        return null;
    }

    return (
        <span className="inline-flex items-center gap-1 rounded-full bg-warning-bg px-2 text-xs font-medium text-warning-fg">
            <LockKeyhole className="size-3" aria-hidden="true" />
            Staff only
        </span>
    );
}

/** The medical panel of the staff candidate profile. */
export function CandidateMedicalPanel({ medical, candidateId }: { medical: ProfileMedical; candidateId: number }) {
    const formatDate = useDateFormatter();
    const full = medical.scope === 'full';
    const grant = medical.access?.grant ?? null;

    return (
        <Panel
            title="Medical Record"
            description={
                full
                    ? `Confidential. Fields marked Staff only are hidden from the candidate.${medical.updatedAt ? ` Last updated ${formatDate.dateTime(medical.updatedAt)}${medical.updatedBy ? ` by ${medical.updatedBy}` : ''}.` : ''}`
                    : grant !== null
                      ? `Shared with you${grant.grantedBy ? ` by ${grant.grantedBy}` : ''} until ${formatDate.dateTime(grant.expiresAt)}. Confidential: each time you open it is recorded.`
                      : 'Confidential. Instructors see it only after the medical staff approve a request.'
            }
            actions={
                full && medical.canEdit ? (
                    <ButtonLink href={routes.medical.records.edit(candidateId)} icon={<Pencil className="size-4" aria-hidden="true" />}>
                        Edit Medical Record
                    </ButtonLink>
                ) : undefined
            }
        >
            <div className="flex flex-col gap-6">
                {medical.entries.length === 0 ? (
                    medical.scope === 'instructor' ? null : (
                        <EmptyState
                            icon={HeartPulse}
                            headingLevel="h3"
                            title="No medical record fields yet"
                            description="An administrator adds the fields of the medical record under Medical Records, Configure Fields."
                        />
                    )
                ) : (
                    <MedicalEntries entries={medical.entries} showAudience={full} />
                )}
                {medical.access !== null && medical.access.grant === null && <AccessRequestBox access={medical.access} candidateId={candidateId} />}
            </div>
        </Panel>
    );
}

/** An instructor's way to ask the medical staff for the full record, and the state of that request. */
function AccessRequestBox({ access, candidateId }: { access: MedicalAccessState; candidateId: number }) {
    const formatDate = useDateFormatter();
    const [requesting, setRequesting] = useState(false);

    return (
        <div className="flex flex-col gap-3 rounded-lg border border-line bg-surface-muted px-4 py-4">
            {access.pending !== null ? (
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="flex items-center gap-2 text-sm text-ink">
                        <Hourglass className="size-4 text-warning-fg" aria-hidden="true" />
                        Your request for the full record (sent {formatDate.dateTime(access.pending.requestedAt)}) is waiting for the medical staff.
                    </p>
                    <ConfirmAction
                        href={routes.medical.access.cancel(access.pending.requestId)}
                        method="post"
                        variant="secondary"
                        size="sm"
                        title="Cancel your request?"
                        description={<p>The medical staff will no longer see it. You can send a new request later.</p>}
                        confirmLabel="Cancel Request"
                    >
                        Cancel Request
                    </ConfirmAction>
                </div>
            ) : (
                <>
                    {access.lastDecision !== null && (
                        <div className="flex flex-col gap-1 text-sm text-ink">
                            <p className="flex flex-wrap items-center gap-2">
                                Your last request: <StatusBadge tone={access.lastDecision.status.tone}>{access.lastDecision.status.label}</StatusBadge>
                                {access.lastDecision.at && <span className="text-ink-muted">{formatDate.dateTime(access.lastDecision.at)}</span>}
                            </p>
                            {access.lastDecision.note && <p className="text-ink-muted">“{access.lastDecision.note}”</p>}
                        </div>
                    )}
                    {access.canRequest && (
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <p className="flex items-center gap-2 text-sm text-ink">
                                <LockKeyhole className="size-4 text-ink-muted" aria-hidden="true" />
                                Need the full medical record? Ask the medical staff, with a reason.
                            </p>
                            <Button variant="secondary" size="sm" onClick={() => setRequesting(true)}>
                                Request Full Record
                            </Button>
                        </div>
                    )}
                </>
            )}
            <RequestAccessDialog open={requesting} candidateId={candidateId} onClose={() => setRequesting(false)} />
        </div>
    );
}

function RequestAccessDialog({ open, candidateId, onClose }: { open: boolean; candidateId: number; onClose: () => void }) {
    const form = useForm({ reason: '' });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(routes.medical.access.store(candidateId), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Dialog open={open} title="Request the Full Medical Record" busy={form.processing} onClose={onClose}>
            <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                <Alert tone="info">The medical staff decide, and choose how long you may see the record (up to 30 days). Each time you open it is recorded.</Alert>
                <FormField
                    label="Why do you need it?"
                    required
                    error={form.errors.reason}
                    hint={`For example, a training activity that needs the candidate's full health information. At least ${REASON_MIN} characters.`}
                >
                    <TextArea value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} maxLength={REASON_MAX} rows={4} />
                </FormField>
                <div className="flex flex-col-reverse gap-2 border-t border-line pt-4 sm:flex-row sm:justify-end">
                    <Button variant="secondary" onClick={onClose} disabled={form.processing}>
                        Cancel
                    </Button>
                    <Button type="submit" loading={form.processing}>
                        Send Request
                    </Button>
                </div>
            </form>
        </Dialog>
    );
}
