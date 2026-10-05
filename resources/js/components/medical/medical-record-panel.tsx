import { FileStack, HeartPulse, LockKeyhole, Pencil } from 'lucide-react';
import { MedicalDocumentList } from '@/components/medical/medical-documents';
import { ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Panel } from '@/components/ui/panel';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatCalendarDate, useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { MedicalEntry, MedicalFieldTypeValue, ProfileMedical } from '@/types/medical';

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

/**
 * The medical panel of the staff candidate profile: everything for medical
 * staff; for instructors of the candidate's class, view only (owner decision
 * 2026-10-02: no request to view; a download needs approval).
 */
export function CandidateMedicalPanel({ medical, candidateId }: { medical: ProfileMedical; candidateId: number }) {
    const formatDate = useDateFormatter();
    const full = medical.scope === 'full';

    return (
        <Panel
            title="Medical Record"
            description={
                full
                    ? `Confidential. Fields marked Staff only are hidden from the candidate.${medical.updatedAt ? ` Last updated ${formatDate.dateTime(medical.updatedAt)}${medical.updatedBy ? ` by ${medical.updatedBy}` : ''}.` : ''}`
                    : 'Confidential. View only (instructors of the class and dietitians of the campus): no printing, downloading or screenshots. Each time you open it is recorded.'
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
                    <EmptyState
                        icon={HeartPulse}
                        headingLevel="h3"
                        title="No medical record fields yet"
                        description="An administrator adds the fields of the medical record under Medical Records, Configure Fields."
                    />
                ) : (
                    <MedicalEntries entries={medical.entries} showAudience={full} />
                )}
                <section aria-labelledby="medical-documents-heading" className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line pb-1.5">
                        <h3 id="medical-documents-heading" className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-primary-800">
                            <FileStack className="size-4" aria-hidden="true" />
                            Uploaded Documents ({medical.documents.length})
                        </h3>
                        {full && medical.waitingDocuments > 0 && (
                            <StatusBadge tone="warning">{medical.waitingDocuments === 1 ? '1 waiting for review' : `${medical.waitingDocuments} waiting for review`}</StatusBadge>
                        )}
                    </div>
                    {!full && (
                        <p className="text-sm text-ink-muted">To keep a copy of a document, request a download; an administrator decides.</p>
                    )}
                    <MedicalDocumentList
                        documents={medical.documents}
                        audience={full ? 'staff' : 'granted'}
                        emptyText="The candidate has not uploaded any medical document yet. Candidates upload certificates and check-up findings in the candidate portal."
                    />
                </section>
            </div>
        </Panel>
    );
}
