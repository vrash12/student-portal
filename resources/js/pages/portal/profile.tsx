import { Head } from '@inertiajs/react';
import { HeartPulse, UserRound } from 'lucide-react';
import { CandidateInformationPanels } from '@/components/candidates/candidate-information';
import { RecordDownloads } from '@/components/candidates/record-downloads';
import { MedicalEntries } from '@/components/medical/medical-record-panel';
import { PortalHeading, PortalSection } from '@/components/portal/portal-ui';
import type { CandidateInformation } from '@/types/candidates';
import type { MedicalEntry } from '@/types/medical';

/**
 * Candidate "My Information": personal and class details, the record
 * downloads, and the medical record fields the administrators share with
 * candidates. Grades, examinations, performance and fitness have their own
 * pages.
 */
export default function CandidateProfile({ candidate, medical }: { candidate: CandidateInformation; medical: MedicalEntry[] }) {
    return (
        <>
            <Head title="My Information" />
            <PortalHeading
                icon={UserRound}
                title="My Information"
                description="Your personal and class details. Contact the academic office to correct anything."
                actions={<RecordDownloads baseUrl="/portal/profile/documents" />}
            />
            <div className="flex flex-col gap-8">
                <CandidateInformationPanels candidate={candidate} />
                {medical.length > 0 && (
                    <PortalSection icon={HeartPulse} title="My Medical Record" description="Recorded by the medical staff. Ask the clinic or the academic office to correct anything.">
                        <MedicalEntries entries={medical} />
                    </PortalSection>
                )}
            </div>
        </>
    );
}
