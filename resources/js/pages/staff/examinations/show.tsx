import { Head } from '@inertiajs/react';
import { ButtonLink } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

interface Examination { id: number; title: string; status: string; duration_minutes: number | null; attempt_limit: number; passing_score: string | null; release_results: boolean; examination_questions?: Array<{ id: number; points: string; question?: { prompt: string } }> }

export default function ExaminationShow({ examination }: { examination: Examination }) {
    return <><Head title={examination.title} /><PageHeader title={examination.title} description="Review questions, monitor grading, and publish when ready." actions={<><ButtonLink href={`/examinations/${examination.id}/questions`}>Manage questions</ButtonLink><ButtonLink href={`/examinations/${examination.id}/grading`}>Essay grading</ButtonLink></>} /><div className="grid gap-4 sm:grid-cols-4"><Summary label="Status" value={examination.status} /><Summary label="Time limit" value={examination.duration_minutes ? `${examination.duration_minutes} minutes` : 'Not set'} /><Summary label="Attempts" value={String(examination.attempt_limit)} /><Summary label="Passing" value={examination.passing_score ? `${examination.passing_score}%` : 'Not set'} /></div><section className="mt-6 rounded-xl border border-line bg-surface p-5"><h2 className="text-lg font-semibold">Candidate results</h2><p className="mt-1 text-sm text-ink-muted">{examination.release_results ? 'Released results are visible after submission.' : 'Results remain private until you release them.'}</p></section></>;
}

function Summary({ label, value }: { label: string; value: string }) { return <div className="rounded-xl border border-line bg-surface p-4"><p className="text-sm text-ink-muted">{label}</p><p className="mt-1 font-semibold capitalize">{value}</p></div>; }
