import { UserRound } from 'lucide-react';
import type { ReactNode } from 'react';
import { Panel } from '@/components/ui/panel';
import { StatusBadge } from '@/components/ui/status-badge';
import { useDateFormatter } from '@/lib/format';
import { terms } from '@/lib/terminology';
import type { CandidateInformation } from '@/types/candidates';

export function CandidateInformationPanels({ candidate }: { candidate: CandidateInformation }) {
    const dates = useDateFormatter();

    return <div className="flex flex-col gap-6">
        <Panel title="Candidate Information">
            <div className="mb-5 flex items-center gap-4">
                {candidate.photoUrl
                    ? <img src={candidate.photoUrl} alt={`Profile of ${candidate.name}`} className="size-24 shrink-0 rounded-lg border border-line object-cover" />
                    : <div className="flex size-24 shrink-0 items-center justify-center rounded-lg border border-line bg-canvas text-ink-muted"><UserRound className="size-10" aria-hidden="true" /><span className="sr-only">No profile photo</span></div>}
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
                <Detail label="Training Group / Section / Platoon">{candidate.trainingGroup ?? 'Not assigned'}</Detail>
                <Detail label="Record Created">{dates.dateTime(candidate.createdAt)}</Detail>
                <Detail label="Last Updated">{dates.dateTime(candidate.updatedAt)}</Detail>
            </dl>
        </Panel>
        {candidate.account && <Panel title="Sign-In Account"><dl className="grid gap-4 sm:grid-cols-2">
            <Detail label="Username">{candidate.account.username}</Detail>
            <Detail label="Account Status"><StatusBadge tone={candidate.account.isActive ? 'success' : 'neutral'}>{candidate.account.isActive ? 'Active' : 'Deactivated'}</StatusBadge></Detail>
            <Detail label="Last Sign-In">{candidate.account.lastLoginAt ? dates.dateTime(candidate.account.lastLoginAt) : 'Never'}</Detail>
        </dl></Panel>}
    </div>;
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return <div><dt className="text-sm text-ink-muted">{label}</dt><dd className="mt-1 break-words font-medium text-ink">{children}</dd></div>;
}
