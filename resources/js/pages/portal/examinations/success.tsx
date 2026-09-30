import { Head } from '@inertiajs/react';
import { useEffect } from 'react';
import { ButtonLink } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { clearRecovery } from '@/lib/exam-recovery';
export default function ExamSuccess({ title, status, attemptId }: { title: string; status: string; attemptId?: number }) {
    useEffect(() => { if (attemptId) void clearRecovery(attemptId); }, [attemptId]);
    const submitted = status === 'submitted';
    return <><Head title={submitted ? 'Submission complete' : 'Time expired'} /><PageHeader title={submitted ? 'Submission complete' : 'Time expired'} description={submitted ? `${title} was submitted. Your saved answers have been preserved.` : `Time expired for ${title}. Your saved answers have been preserved for instructor review.`} /><ButtonLink href="/portal">Return to examinations</ButtonLink></>;
}
