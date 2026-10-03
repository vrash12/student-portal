import { GraduationCap, Pencil } from 'lucide-react';
import type { ReactNode } from 'react';
import { ButtonLink } from '@/components/ui/button';
import { Panel } from '@/components/ui/panel';
import { formatCalendarDate } from '@/lib/format';
import type { CandidateBackground } from '@/types/candidates';

const NOT_RECORDED = 'Not recorded';

/**
 * The candidate's background record (owner request, 2026-10-03): personal
 * details and emergency contact (administrators and the candidate only),
 * education, and service background. Read-only; administrators edit it on
 * the Edit Background page (editUrl).
 */
export function CandidateBackgroundPanels({ background, editUrl = null }: { background: CandidateBackground; editUrl?: string | null }) {
    const edit = editUrl !== null && (
        <ButtonLink href={editUrl} variant="secondary" size="sm" icon={<Pencil className="size-4" aria-hidden="true" />}>
            Edit Background
        </ButtonLink>
    );
    const { personal, service, education } = background;

    return (
        <div className="flex flex-col gap-6">
            {personal !== null && (
                <Panel title="Personal Details" description="Personal and contact details. Not shown to instructors." actions={edit}>
                    <dl className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        <Detail label="Date of Birth">
                            {personal.dateOfBirth === null ? NOT_RECORDED : `${formatCalendarDate(personal.dateOfBirth)}${personal.age === null ? '' : ` (${personal.age} years old)`}`}
                        </Detail>
                        <Detail label="Place of Birth">{personal.placeOfBirth ?? NOT_RECORDED}</Detail>
                        <Detail label="Sex">{personal.sex ?? NOT_RECORDED}</Detail>
                        <Detail label="Civil Status">{personal.civilStatus ?? NOT_RECORDED}</Detail>
                        <Detail label="Mobile Number">{personal.mobileNumber ?? NOT_RECORDED}</Detail>
                        <Detail label="Personal Email">{personal.personalEmail ?? NOT_RECORDED}</Detail>
                        <Detail label="Home Address" wide>
                            {personal.homeAddress ?? NOT_RECORDED}
                        </Detail>
                    </dl>
                    <h3 className="mt-6 border-t border-line pt-4 text-sm font-semibold text-ink">Emergency Contact</h3>
                    <dl className="mt-3 grid gap-4 sm:grid-cols-3">
                        <Detail label="Name">{personal.emergencyContact.name ?? NOT_RECORDED}</Detail>
                        <Detail label="Relationship">{personal.emergencyContact.relationship ?? NOT_RECORDED}</Detail>
                        <Detail label="Phone">{personal.emergencyContact.phone ?? NOT_RECORDED}</Detail>
                    </dl>
                </Panel>
            )}

            <Panel
                title="Education and Background"
                description="Education attained, eligibility and prior service."
                actions={personal === null ? edit : undefined}
                bodyClassName="p-0"
            >
                {education.length === 0 ? (
                    <p className="px-5 py-4 text-sm text-ink-muted">No education recorded.</p>
                ) : (
                    <ul className="divide-y divide-line">
                        {education.map((entry, index) => (
                            <li key={`${entry.level}-${index}`} className="flex gap-3 px-5 py-4">
                                <GraduationCap className="mt-0.5 size-5 shrink-0 text-primary-700" aria-hidden="true" />
                                <div className="min-w-0">
                                    <p className="font-semibold text-ink">{entry.degree}</p>
                                    <p className="text-sm text-ink-muted">
                                        {entry.levelLabel} · {entry.school}
                                        {entry.yearGraduated !== null && ` · ${entry.yearGraduated}`}
                                    </p>
                                    {entry.honors !== null && <p className="text-sm text-ink">{entry.honors}</p>}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                <dl className="grid gap-4 border-t border-line px-5 py-4 sm:grid-cols-3">
                    <Detail label="Eligibility / Licenses">{service.eligibility ?? NOT_RECORDED}</Detail>
                    <Detail label="Prior Military or Reserve Service">{service.priorService ?? NOT_RECORDED}</Detail>
                    <Detail label="Previous Occupation">{service.previousOccupation ?? NOT_RECORDED}</Detail>
                </dl>
            </Panel>
        </div>
    );
}

function Detail({ label, children, wide = false }: { label: string; children: ReactNode; wide?: boolean }) {
    return (
        <div className={wide ? 'sm:col-span-2 xl:col-span-3' : undefined}>
            <dt className="text-sm text-ink-muted">{label}</dt>
            <dd className="mt-1 break-words font-medium text-ink">{children}</dd>
        </div>
    );
}
