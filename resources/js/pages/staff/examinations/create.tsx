import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

interface Offering { id: number; subject: { name: string }; class_batch: { name: string } }

export default function ExaminationCreate({ offerings = [] }: { offerings?: Offering[] }) {
    const form = useForm({ class_subject_id: offerings[0]?.id?.toString() ?? '', title: '', description: '', duration_minutes: '30', attempt_limit: '1', passing_score: '', release_results: false, opens_at: '', closes_at: '' });

    function submit(event: FormEvent): void {
        event.preventDefault();
        form.post('/examinations');
    }

    return <>
        <Head title="Create Examination" />
        <PageHeader title="Create Examination" description="Create a draft, then add questions and review it before publishing." />
        <form onSubmit={submit} className="max-w-3xl space-y-6 rounded-xl border border-line bg-surface p-6">
            <div className="grid gap-5 sm:grid-cols-2">
                <label className="block sm:col-span-2">Class and subject<select className="mt-2 block min-h-12 w-full rounded border border-line-strong bg-surface p-3" value={form.data.class_subject_id} onChange={(event) => form.setData('class_subject_id', event.target.value)} required><option value="">Select an assigned class and subject</option>{offerings.map((offering) => <option key={offering.id} value={offering.id}>{offering.subject.name} · {offering.class_batch.name}</option>)}</select></label>
                <label className="block sm:col-span-2">Title<input className="mt-2 block min-h-12 w-full rounded border border-line-strong p-3" value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} required maxLength={200} /></label>
                <label className="block sm:col-span-2">Instructions<textarea className="mt-2 block min-h-28 w-full rounded border border-line-strong p-3" value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} /></label>
                <label className="block">Time limit (minutes)<input type="number" min="1" className="mt-2 block min-h-12 w-full rounded border border-line-strong p-3" value={form.data.duration_minutes} onChange={(event) => form.setData('duration_minutes', event.target.value)} required /></label>
                <label className="block">Attempt limit<input type="number" min="1" className="mt-2 block min-h-12 w-full rounded border border-line-strong p-3" value={form.data.attempt_limit} onChange={(event) => form.setData('attempt_limit', event.target.value)} required /></label>
                <label className="block">Passing score (%)<input type="number" min="0" max="100" step="0.01" className="mt-2 block min-h-12 w-full rounded border border-line-strong p-3" value={form.data.passing_score} onChange={(event) => form.setData('passing_score', event.target.value)} /></label>
                <div className="flex items-end"><label className="flex min-h-12 items-center gap-3"><input type="checkbox" className="size-5" checked={form.data.release_results} onChange={(event) => form.setData('release_results', event.target.checked)} />Release results to candidates after submission</label></div>
            </div>
            {Object.values(form.errors).map((error) => <p key={error} role="alert" className="text-danger-fg">{error}</p>)}
            <Button type="submit" loading={form.processing} disabled={offerings.length === 0}>Save draft</Button>
        </form>
    </>;
}
