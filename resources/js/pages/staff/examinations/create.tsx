import { Head, useForm, usePage } from '@inertiajs/react';
import { Button, ButtonLink } from '@/components/ui/button';
import { FormField, SelectInput, TextInput, TextArea } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';

interface Offering { id: number; subject: { name: string }; class_batch: { name: string } }
interface Settings { kind: string; title: string; description: string; duration_minutes: string; attempt_limit: string; passing_score: string; access_code: string; opens_at: string; closes_at: string; release_results: boolean; randomize_questions: boolean; randomize_choices: boolean; one_question_at_a_time: boolean; allow_back_navigation: boolean; auto_submit: boolean }
interface Exam extends Omit<Settings, 'duration_minutes' | 'attempt_limit' | 'passing_score'> { id: number; class_subject_id: number; duration_minutes: number | null; attempt_limit: number; passing_score: string | null }
const toggles = { randomize_questions: 'Randomize question order', randomize_choices: 'Randomize multiple-choice options', one_question_at_a_time: 'Show one question at a time', allow_back_navigation: 'Allow returning to previous questions', auto_submit: 'Automatically submit saved answers when time expires', release_results: 'Release results to candidates after submission' } as const;

export default function ExaminationCreate({ offerings, examination }: { offerings: Offering[]; examination?: Exam }) {
    const { app } = usePage().props;
    const form = useForm({ class_subject_id: String(examination?.class_subject_id ?? offerings[0]?.id ?? ''), kind: examination?.kind ?? 'examination', title: examination?.title ?? '', description: examination?.description ?? '', duration_minutes: String(examination?.duration_minutes ?? '30'), attempt_limit: String(examination?.attempt_limit ?? 1), passing_score: examination?.passing_score ?? '', access_code: examination?.access_code ?? '', opens_at: examination?.opens_at ?? '', closes_at: examination?.closes_at ?? '', release_results: examination?.release_results ?? false, randomize_questions: examination?.randomize_questions ?? false, randomize_choices: examination?.randomize_choices ?? false, one_question_at_a_time: examination?.one_question_at_a_time ?? false, allow_back_navigation: examination?.allow_back_navigation ?? true, auto_submit: examination?.auto_submit ?? true });
    return <><Head title={examination ? 'Edit examination' : 'Create examination'} /><PageHeader title={examination ? 'Examination settings' : 'Create quiz or examination'} description="Save a draft, select questions, then review before publishing." actions={<ButtonLink href={examination ? `/examinations/${examination.id}` : '/examinations'} variant="secondary">Cancel</ButtonLink>} />
        <form onSubmit={(e) => { e.preventDefault(); if (examination) { form.transform(({ class_subject_id: _offering, ...data }) => data); form.put(`/examinations/${examination.id}`); } else { form.post('/examinations'); } }} className="max-w-4xl space-y-6 rounded-xl border border-line bg-surface p-6">
            <div className="grid gap-5 sm:grid-cols-2">
                <FormField label="Class and subject" required error={form.errors.class_subject_id}><SelectInput value={form.data.class_subject_id} disabled={!!examination} onChange={(e) => form.setData('class_subject_id', e.target.value)}><option value="">Choose assignment</option>{offerings.map((o) => <option key={o.id} value={o.id}>{o.subject.name} · {o.class_batch.name}</option>)}</SelectInput></FormField>
                <FormField label="Type" required error={form.errors.kind}><SelectInput value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value)}><option value="examination">Examination</option><option value="quiz">Quiz</option></SelectInput></FormField>
                <FormField label="Title" className="sm:col-span-2" required error={form.errors.title}><TextInput maxLength={200} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} /></FormField>
                <FormField label="Instructions" className="sm:col-span-2" error={form.errors.description}><TextArea rows={4} maxLength={10000} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} /></FormField>
                <FormField label="Time limit (minutes)" hint="Required before publication." error={form.errors.duration_minutes}><TextInput type="number" min="1" max="1440" value={form.data.duration_minutes} onChange={(e) => form.setData('duration_minutes', e.target.value)} /></FormField>
                <FormField label="Attempt limit" required error={form.errors.attempt_limit}><TextInput type="number" min="1" max="100" value={form.data.attempt_limit} onChange={(e) => form.setData('attempt_limit', e.target.value)} /></FormField>
                <FormField label="Passing score (%)" hint="Optional; results have no pass/fail outcome when unset." error={form.errors.passing_score}><TextInput type="number" min="0" max="100" step="0.01" value={form.data.passing_score} onChange={(e) => form.setData('passing_score', e.target.value)} /></FormField>
                <FormField label="Access code" hint="Optional. Candidates must enter this to start." error={form.errors.access_code}><TextInput autoComplete="off" maxLength={100} value={form.data.access_code} onChange={(e) => form.setData('access_code', e.target.value)} /></FormField>
                <FormField label="Available from" hint={app.timezone} error={form.errors.opens_at}><TextInput type="datetime-local" value={form.data.opens_at} onChange={(e) => form.setData('opens_at', e.target.value)} /></FormField>
                <FormField label="Available until" hint="Attempts end at the earlier of this time or their time limit." error={form.errors.closes_at}><TextInput type="datetime-local" value={form.data.closes_at} onChange={(e) => form.setData('closes_at', e.target.value)} /></FormField>
            </div>
            <fieldset className="space-y-2"><legend className="mb-2 font-semibold">Delivery settings</legend>{Object.entries(toggles).map(([key, label]) => { const field = key as keyof typeof toggles; return <label key={key} className="flex min-h-11 items-center gap-3"><input type="checkbox" className="size-5" checked={form.data[field]} onChange={(e) => form.setData(field, e.target.checked)} />{label}</label>; })}</fieldset>
            {Object.values(form.errors).map((error, i) => <p role="alert" key={i} className="text-sm text-danger-fg">{error}</p>)}
            <Button type="submit" loading={form.processing} disabled={offerings.length === 0}>Save draft</Button>
        </form>
    </>;
}

