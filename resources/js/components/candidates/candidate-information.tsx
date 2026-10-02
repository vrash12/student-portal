import { Download, QrCode, UserRound } from 'lucide-react';
import type { ReactNode } from 'react';
import { buttonClasses } from '@/components/ui/button';
import { ConfirmAction } from '@/components/ui/confirm-action';
import { Panel } from '@/components/ui/panel';
import { StatusBadge } from '@/components/ui/status-badge';
import { useDateFormatter } from '@/lib/format';
import { terms } from '@/lib/terminology';
import type { CandidateInformation } from '@/types/candidates';

interface CandidateInformationPanelsProps {
    candidate: CandidateInformation;
    /** Staff with candidates.manage: where to issue a new QR code (the old one stops working). */
    qrReissueUrl?: string | null;
    /** The candidate's own page (portal) or a staff profile; changes the QR code wording. */
    audience?: 'staff' | 'candidate';
}

export function CandidateInformationPanels({ candidate, qrReissueUrl = null, audience = 'staff' }: CandidateInformationPanelsProps) {
    const dates = useDateFormatter();

    return <div className="flex flex-col gap-6">
        <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_19rem]">
        <Panel title="Candidate Information">
            <div className="mb-5 flex items-center gap-4">
                {candidate.photoUrl
                    ? <img src={candidate.photoUrl} alt={`Profile of ${candidate.name}`} className="size-24 shrink-0 rounded-lg border border-line-box object-cover" />
                    : <div className="flex size-24 shrink-0 items-center justify-center rounded-lg border border-line-box bg-canvas text-ink-muted"><UserRound className="size-10" aria-hidden="true" /><span className="sr-only">No profile photo</span></div>}
                <div className="min-w-0"><p className="break-words text-lg font-semibold text-ink">{candidate.name}</p><p className="text-sm text-ink-muted">Candidate {candidate.candidateNumber}</p></div>
            </div>
            <dl className="grid gap-4 sm:grid-cols-2">
                <Detail label="First Name">{candidate.firstName}</Detail>
                <Detail label="Middle Name">{candidate.middleName ?? 'Not provided'}</Detail>
                <Detail label="Last Name">{candidate.lastName}</Detail>
                <Detail label="Suffix">{candidate.suffix ?? 'None'}</Detail>
                <Detail label={terms.classBatch.singular}>{candidate.classBatch?.name ?? 'Not assigned'}</Detail>
                <Detail label="Candidate Status"><StatusBadge tone={candidate.status.tone}>{candidate.status.label}</StatusBadge></Detail>
                <Detail label="Academic Period">{candidate.classBatch?.period ?? 'Not assigned'}</Detail>
                <Detail label="Training Group / Section">{candidate.trainingGroup ?? 'Not assigned'}</Detail>
                <Detail label="Company">{candidate.company ?? 'Not assigned'}</Detail>
                <Detail label="Platoon">{candidate.platoon ?? 'Not assigned'}</Detail>
                <Detail label="Record Created">{dates.dateTime(candidate.createdAt)}</Detail>
                <Detail label="Last Updated">{dates.dateTime(candidate.updatedAt)}</Detail>
            </dl>
        </Panel>
        <QrCodePanel candidate={candidate} reissueUrl={qrReissueUrl} audience={audience} />
        </div>
        {candidate.account && <Panel title="Sign-In Account"><dl className="grid gap-4 sm:grid-cols-2">
            <Detail label="Username">{candidate.account.username}</Detail>
            <Detail label="Account Status"><StatusBadge tone={candidate.account.isActive ? 'success' : 'neutral'}>{candidate.account.isActive ? 'Active' : 'Deactivated'}</StatusBadge></Detail>
            <Detail label="Last Sign-In">{candidate.account.lastLoginAt ? dates.dateTime(candidate.account.lastLoginAt) : 'Never'}</Detail>
        </dl></Panel>}
    </div>;
}

/**
 * The candidate's QR code (owner request, 2026-10-02): their identification
 * for attendance. Instructors scan it on a session's scanner; opened with a
 * phone camera it leads signed-in staff to this profile.
 */
function QrCodePanel({ candidate, reissueUrl, audience }: { candidate: CandidateInformation; reissueUrl: string | null; audience: 'staff' | 'candidate' }) {
    return (
        <Panel
            title="QR Code"
            description={audience === 'candidate' ? 'Show this code to your instructor to record your attendance. It is yours only: do not share a picture of it.' : 'For attendance: instructors scan it on a session’s scanner.'}
        >
            <figure className="flex flex-col items-center gap-3">
                <img src={candidate.qrCodeUrl} alt={`QR code of ${candidate.name}, candidate ${candidate.candidateNumber}`} width={224} height={224} className="size-56 rounded-lg border border-line-box bg-white p-1" />
                <figcaption className="text-center text-sm">
                    <span className="block font-semibold text-ink">{candidate.name}</span>
                    <span className="flex items-center justify-center gap-1.5 text-ink-muted"><QrCode className="size-4" aria-hidden="true" />Candidate {candidate.candidateNumber}</span>
                </figcaption>
            </figure>
            <div className="mt-4 flex flex-wrap justify-center gap-2">
                <a href={candidate.qrCardUrl} className={buttonClasses('secondary', 'sm')}>
                    <Download className="size-4" aria-hidden="true" />
                    Save as PDF
                </a>
                {reissueUrl !== null && (
                    <ConfirmAction
                        href={reissueUrl}
                        method="post"
                        variant="ghost"
                        size="sm"
                        tone="primary"
                        title="Issue a new QR code?"
                        description={<p>Use this when a card is lost or a code was shared. The current code stops working at once, on printed cards too; print or show the new one.</p>}
                        confirmLabel="Issue New Code"
                    >
                        Issue New Code
                    </ConfirmAction>
                )}
            </div>
        </Panel>
    );
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return <div><dt className="text-sm text-ink-muted">{label}</dt><dd className="mt-1 break-words font-medium text-ink">{children}</dd></div>;
}
