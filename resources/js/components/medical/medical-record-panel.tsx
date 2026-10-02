import { GraduationCap, HeartPulse, Pencil, UserRound } from 'lucide-react';
import { ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Panel } from '@/components/ui/panel';
import { formatCalendarDate, useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { MedicalEntry, MedicalFieldTypeValue, ProfileMedical } from '@/types/medical';

/** A recorded value as people read it; null when not recorded. */
export function formatMedicalValue(type: MedicalFieldTypeValue, value: string | null): string | null {
    if (value === null || value === '') {
        return null;
    }
    if (type === 'yes_no') {
        return value === 'yes' ? 'Yes' : 'No';
    }
    if (type === 'date') {
        return formatCalendarDate(value);
    }

    return value;
}

/** Fields and values of a medical record, two columns on wide screens. */
export function MedicalEntries({ entries, showAudience = false }: { entries: MedicalEntry[]; showAudience?: boolean }) {
    return (
        <dl className="grid gap-x-8 gap-y-5 sm:grid-cols-2">
            {entries.map((entry) => {
                const shown = formatMedicalValue(entry.type, entry.value);

                return (
                    <div key={entry.fieldId} className={entry.type === 'long_text' ? 'sm:col-span-2' : undefined}>
                        <dt className="flex flex-wrap items-center gap-2 text-sm text-ink-muted">
                            {entry.name}
                            {showAudience && <Audience instructors={entry.visibleToInstructors} candidate={entry.visibleToCandidate} />}
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

/** Small marks telling medical staff who else sees a field. Always text, never colour alone. */
export function Audience({ instructors, candidate }: { instructors: boolean; candidate: boolean }) {
    return (
        <>
            {instructors && (
                <span className="inline-flex items-center gap-1 rounded-full bg-primary-50 px-2 text-xs font-medium text-primary-800">
                    <GraduationCap className="size-3" aria-hidden="true" />
                    Instructors
                </span>
            )}
            {candidate && (
                <span className="inline-flex items-center gap-1 rounded-full bg-surface-muted px-2 text-xs font-medium text-ink">
                    <UserRound className="size-3" aria-hidden="true" />
                    Candidate
                </span>
            )}
        </>
    );
}

/** The medical panel of the staff candidate profile. */
export function CandidateMedicalPanel({ medical, candidateId }: { medical: ProfileMedical; candidateId: number }) {
    const formatDate = useDateFormatter();
    const full = medical.scope === 'full';

    return (
        <Panel
            title={full ? 'Medical Record' : 'Medical Notes'}
            description={
                full
                    ? `Confidential. Marks show who else sees a field.${medical.updatedAt ? ` Last updated ${formatDate.dateTime(medical.updatedAt)}${medical.updatedBy ? ` by ${medical.updatedBy}` : ''}.` : ''}`
                    : 'Shared with the instructors of this candidate by the administrators. Confidential.'
            }
            actions={
                full && medical.canEdit ? (
                    <ButtonLink href={routes.medical.records.edit(candidateId)} icon={<Pencil className="size-4" aria-hidden="true" />}>
                        Edit Medical Record
                    </ButtonLink>
                ) : undefined
            }
        >
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
        </Panel>
    );
}
