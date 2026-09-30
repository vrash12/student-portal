import { Head } from '@inertiajs/react';
import { useEffect } from 'react';
import { ButtonLink } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { clearRecovery } from '@/lib/exam-recovery';
export default function ExamSuccess({ title, status, attemptId, releaseResults, resultStatus, objectivePoints, objectiveMaxPoints, percentage, passed }: { title: string; status: string; attemptId?: number; releaseResults?: boolean; resultStatus?: string|null; objectivePoints?: string|null; objectiveMaxPoints?: string|null; percentage?: string|null; passed?: boolean|null }) {
    useEffect(() => { if (attemptId) void clearRecovery(attemptId); }, [attemptId]);
    const submitted = status === 'submitted';
    return <><Head title={submitted ? 'Submission complete' : 'Time expired'} /><PageHeader title={submitted ? 'Submission complete' : 'Time expired'} description={submitted ? `${title} was submitted. Your saved answers have been preserved.` : `Time expired for ${title}. Your saved answers have been preserved for instructor review.`} />{submitted && releaseResults && <section className="mb-6 max-w-xl rounded-xl border border-line bg-surface p-6"><h2 className="text-lg font-semibold">Result</h2>{resultStatus === 'pending_review' ? <p className="mt-2">Objective items: {objectivePoints ?? '0'} / {objectiveMaxPoints ?? '0'}. Essay responses are pending instructor review.</p> : <p className="mt-2">Score: {percentage ?? '—'}% {passed === true ? '· Passing' : passed === false ? '· Not passing' : ''}</p>}</section>}<ButtonLink href="/portal">Return to examinations</ButtonLink></>;
}
