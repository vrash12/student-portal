import { Head } from '@inertiajs/react';
import { UserRound } from 'lucide-react';
import { CandidateInformationPanels } from '@/components/candidates/candidate-information';
import { RecordDownloads } from '@/components/candidates/record-downloads';
import { PortalHeading } from '@/components/portal/portal-ui';
import type { CandidateInformation } from '@/types/candidates';

/**
 * Candidate "My Information": personal and class details and the record
 * downloads. Grades, examinations, performance and fitness have their own
 * pages.
 */
export default function CandidateProfile({ candidate }: { candidate: CandidateInformation }) {
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
            </div>
        </>
    );
}
