import { Head, router, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
interface ExamAttempt { number:number; status:string; submittedAt:string|null; resultStatus:string|null; percentage:string|null }
interface Exam { id:number; title:string; description:string|null; durationMinutes:number; questionCount:number; attemptLimit:number; attemptsUsed:number; requiresCode:boolean; available:boolean; resumeId:number|null; allowBackNavigation:boolean; releaseResults:boolean; attempts:ExamAttempt[] }
export default function ExamStart({examination:e}:{examination:Exam}) {
 const form=useForm({access_code:''});
 return <><Head title={e.title}/><PageHeader title={e.title} description="Review the instructions before starting."/>
 <form onSubmit={event=>{event.preventDefault();form.post(`/portal/examinations/${e.id}/start`);}} className="max-w-2xl space-y-6 rounded-xl border border-line bg-surface p-6">
 <p className="whitespace-pre-wrap">{e.description}</p>
 <dl className="grid gap-4 sm:grid-cols-3"><div><dt>Questions</dt><dd className="text-xl font-semibold">{e.questionCount}</dd></div><div><dt>Time limit</dt><dd className="text-xl font-semibold">{e.durationMinutes} minutes</dd></div><div><dt>Attempts used</dt><dd className="text-xl font-semibold">{e.attemptsUsed} / {e.attemptLimit}</dd></div></dl>
 <p>The timer starts when you begin and continues if you leave this page. {e.allowBackNavigation?'You may return to earlier questions and flag them for review.':'You cannot return to a question after selecting Next.'}</p>
 {e.requiresCode&&!e.resumeId&&<label className="block">Access code<input type="password" autoComplete="off" className="mt-2 block min-h-12 w-full rounded border border-line-strong p-3" value={form.data.access_code} onChange={event=>form.setData('access_code',event.target.value)}/></label>}
 {Object.values(form.errors).map((error,i)=><p key={i} role="alert" className="text-danger-fg">{error}</p>)}
 {e.resumeId?<Button type="button" className="min-h-12" onClick={()=>router.visit(`/portal/attempts/${e.resumeId}`)}>Resume examination</Button>:<Button type="submit" className="min-h-12" loading={form.processing} disabled={!e.available||e.attemptsUsed>=e.attemptLimit}>Start examination</Button>}
 </form>
 <section className="mt-6 max-w-2xl rounded-xl border border-line bg-surface p-6" aria-labelledby="attempt-history"><h2 id="attempt-history" className="text-lg font-semibold">Attempt history</h2><div className="mt-3 space-y-2">{e.attempts.map((attempt)=><div key={attempt.number} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line p-3 text-sm"><span>Attempt {attempt.number}</span><span className="capitalize">{attempt.status.replace('_',' ')}</span><span>{attempt.resultStatus === 'pending_review' ? 'Pending review' : e.releaseResults && attempt.percentage !== null ? `${attempt.percentage}%` : '—'}</span></div>)}</div></section></>;
}
