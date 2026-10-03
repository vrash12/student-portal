import { Head } from '@inertiajs/react';
import { UserRound } from 'lucide-react';
import { CandidateBackgroundPanels } from '@/components/candidates/candidate-background';
import { CandidateInformationPanels } from '@/components/candidates/candidate-information';
import { RecordDownloads } from '@/components/candidates/record-downloads';
import { PortalHeading } from '@/components/portal/portal-ui';
import type { CandidateBackground, CandidateInformation } from '@/types/candidates';

/**
 * Candidate "My Information": personal and class details and the record
 * downloads. Grades, examinations, performance, fitness and the medical
 * record have their own pages.
 */
export default function CandidateProfile({ candidate, background }: { candidate: CandidateInformation; background: CandidateBackground }) {
    return (
        <>
            <Head title="My Information" />
            <PortalHeading
                icon={UserRound}
                title="My Information"
                description="Your personal and class details. The Registration PDF also lists the expenses the institution provides for you. Contact the academic office to correct anything."
                actions={<RecordDownloads baseUrl="/portal/profile/documents" />}
            />
            <div className="flex flex-col gap-8">
                <CandidateInformationPanels candidate={candidate} audience="candidate" />
                <CandidateBackgroundPanels background={background} />
            </div>
        </>
    );
}
