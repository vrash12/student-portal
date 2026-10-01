import { Head } from '@inertiajs/react';
import { ArrowRight, BookOpen, CalendarDays, ClipboardList, Clock3, FileText, UserRound } from 'lucide-react';
import { ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Panel } from '@/components/ui/panel';
import { Pagination } from '@/components/ui/pagination';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { OverallStandingValue } from '@/components/grading/standing';
import { formatCalendarDate, formatPercent, useDateFormatter } from '@/lib/format';
import type { Paginated } from '@/types';
import type { OverallStanding } from '@/types/grading';

interface Exam {
    id: number; title: string; kind: string; subject: string;
    durationMinutes: number; opensAt: string | null; closesAt: string | null;
    attemptsUsed: number; attemptLimit: number; resumeId: number | null;
}
interface HomeProps {
    summary: { name: string; number: string; className: string | null; period: string | null; subjectCount: number; overall: OverallStanding; eligible: boolean };
    available: Paginated<Exam>;
    upcoming: Paginated<Exam>;
    outstanding: Paginated<{ id: number; title: string; subject: string; category: string; date: string | null }>;
    recentResults: Array<{ id: number; title: string; subject: string; kind: string; attemptNumber: number; submittedAt: string | null; percentage: number | null; passed: boolean | null }>;
}

export default function PortalHome({ summary, available, upcoming, outstanding, recentResults }: HomeProps) {
    const dates = useDateFormatter();
    return <>
        <Head title="My Home" />
        <section className="brand-dark mb-6 overflow-hidden rounded-2xl border border-primary-800 bg-primary-800 text-white shadow-md" aria-labelledby="welcome-title">
            <div className="grid gap-6 p-6 sm:p-8 lg:grid-cols-[1fr_auto] lg:items-center">
                <div className="min-w-0">
                    <p className="mb-3 text-xs font-bold uppercase tracking-[0.18em] text-accent-300">My Home</p>
                    <h1 id="welcome-title" className="text-2xl font-bold tracking-tight sm:text-3xl">Welcome, {summary.name}</h1>
                    <p className="mt-3 max-w-xl text-sm leading-relaxed text-primary-100">Your examinations, results and academic standing.</p>
                    <div className="mt-5 flex flex-wrap gap-2 text-xs font-medium">
                        <span className="rounded-md border border-white/20 px-3 py-2">Candidate {summary.number}</span>
                        <span className="rounded-md border border-white/20 px-3 py-2">{summary.className ?? 'Class not assigned'}</span>
                        {summary.period && <span className="rounded-md border border-white/20 px-3 py-2">{summary.period}</span>}
                    </div>
                </div>
                <ButtonLink href="/portal/profile" variant="accent" size="lg" icon={<UserRound className="size-5" aria-hidden="true" />}>My Information<ArrowRight className="size-4" aria-hidden="true" /></ButtonLink>
            </div>
            <nav aria-label="Home shortcuts" className="grid grid-cols-1 border-t border-white/15 bg-primary-900/40 sm:grid-cols-3">
                {[{ href: '#available-examinations', label: 'Open examinations', value: available.total, icon: ClipboardList }, { href: '#upcoming-examinations', label: 'Coming up', value: upcoming.total, icon: CalendarDays }, { href: '#recent-results', label: 'Recent released results', value: recentResults.length, icon: FileText }].map((item) => <a key={item.href} href={item.href} className="flex min-h-16 items-center gap-3 px-6 py-4 text-sm hover:bg-white/10 sm:px-8"><item.icon className="size-5 text-accent-300" aria-hidden="true" /><span className="flex-1">{item.label}</span><span className="text-lg font-bold tabular-nums text-accent-100">{item.value}</span><ArrowRight className="size-4 text-primary-200" aria-hidden="true" /></a>)}
            </nav>
        </section>
        <div className="flex flex-col gap-6">
            <Panel title="Academic Summary" description="Current standing from finalized assessments in your assigned class.">
                <div className="grid gap-5 sm:grid-cols-3">
                    <div className="rounded-lg border border-line bg-surface-muted p-4"><p className="mb-1 text-sm text-ink-muted">Overall standing</p>{summary.overall.standing ? <OverallStandingValue overall={summary.overall} /> : <p className="text-sm">Not available yet</p>}</div>
                    <div className="rounded-lg border border-primary-200 bg-primary-50 p-4"><BookOpen className="mb-3 size-5 text-primary-600" aria-hidden="true" /><p className="text-sm text-primary-800">Enrolled subjects</p><p className="mt-1 text-3xl font-bold tabular-nums text-primary-900">{summary.subjectCount}</p></div>
                    <div className="rounded-lg border border-accent-300 bg-accent-50 p-4"><ClipboardList className="mb-3 size-5 text-accent-800" aria-hidden="true" /><p className="text-sm text-accent-800">Assessments awaiting a score</p><p className="mt-1 text-3xl font-bold tabular-nums text-primary-900">{outstanding.total}</p></div>
                </div>
                {!summary.eligible && <p className="mt-4 text-sm text-ink-muted">An eligible class assignment is required to take examinations. Contact the academic office to review your enrollment.</p>}
            </Panel>
            <div id="available-examinations" className="scroll-mt-6"><Panel title="Available Examinations" description="Start an open assessment, continue an attempt or review your submissions." bodyClassName="p-0">
                {available.data.length === 0 ? <div className="p-5"><EmptyState icon={ClipboardList} headingLevel="h3" title="No examinations open now" description="Scheduled examinations are listed below when your instructor publishes them." /></div> : <div className="grid gap-4 p-5 sm:grid-cols-2">{available.data.map((exam) => <ExamCard key={exam.id} exam={exam} />)}</div>}
                <Pagination page={available} noun={{ one: 'examination', other: 'examinations' }} />
            </Panel>
            </div><div id="upcoming-examinations" className="scroll-mt-6"><Panel title="Upcoming Examinations" description="Published assessments scheduled for your class. Times use the institution’s timezone." bodyClassName="p-0">
                {upcoming.data.length === 0 ? <p className="p-5 text-sm text-ink-muted">No upcoming examinations scheduled.</p> : <div className="grid gap-4 p-5 sm:grid-cols-2">{upcoming.data.map((exam) => <ExamCard key={exam.id} exam={exam} upcoming />)}</div>}
                <Pagination page={upcoming} noun={{ one: 'scheduled examination', other: 'scheduled examinations' }} />
            </Panel>
            </div><div className="grid items-start gap-6 lg:grid-cols-2">
                <Panel title="Outstanding Assessments" description="Finalized assessments with no recorded score. Work may be outstanding or grading may still be pending; check with your instructor." bodyClassName="p-0">
                    {outstanding.data.length === 0 ? <p className="p-5 text-sm text-ink-muted">No finalized assessments are missing a score.</p> : <ul className="divide-y divide-line">{outstanding.data.map((item) => <li key={item.id} className="p-5"><p className="font-semibold">{item.title}</p><p className="mt-1 text-sm text-ink-muted">{item.subject} · {item.category}</p>{item.date && <p className="text-sm text-ink-muted">Assessment date: {formatCalendarDate(item.date)}</p>}<div className="mt-2"><StatusBadge tone="warning">Awaiting score</StatusBadge></div></li>)}</ul>}
                    <Pagination page={outstanding} noun={{ one: 'assessment', other: 'assessments' }} />
                </Panel>
                <div id="recent-results" className="min-w-0 scroll-mt-6"><Panel title="Recent Released Results" description="Your six most recent graded submissions whose results have been released." bodyClassName="p-0">
                    {recentResults.length === 0 ? <p className="p-5 text-sm text-ink-muted">No released results yet. Results appear after grading and instructor release.</p> : <ul className="divide-y divide-line">{recentResults.map((result) => <li key={result.id} className="p-5"><div className="flex items-start justify-between gap-3"><div><p className="font-semibold">{result.title}</p><p className="mt-1 text-sm text-ink-muted">{result.subject} · {result.kind}</p></div><span className="font-semibold tabular-nums">{formatPercent(result.percentage)}</span></div><p className="mt-1 text-xs text-ink-muted">Attempt {result.attemptNumber} · Submitted {dates.dateTime(result.submittedAt)}</p>{result.passed !== null && <div className="mt-2"><StatusBadge tone={result.passed ? 'success' : 'danger'}>{result.passed ? 'Passed' : 'Not passed'}</StatusBadge></div>}</li>)}</ul>}
                    <div className="border-t border-line p-4"><ButtonLink href="/portal/profile" variant="secondary">View Academic Record</ButtonLink></div>
                </Panel></div>
            </div>
        </div>
    </>;
}

function ExamCard({ exam, upcoming = false }: { exam: Exam; upcoming?: boolean }) {
    const dates = useDateFormatter();
    const exhausted = exam.attemptsUsed >= exam.attemptLimit;
    // The state of this examination for the candidate, in words and with an icon (never color alone).
    const state: { tone: StatusTone; label: string } = upcoming
        ? { tone: 'neutral', label: 'Scheduled' }
        : exam.resumeId
          ? { tone: 'warning', label: 'In progress' }
          : exhausted
            ? { tone: 'neutral', label: 'All attempts used' }
            : { tone: 'success', label: 'Open now' };

    return <article className="flex flex-col rounded-xl border border-line border-t-4 border-t-primary-600 bg-surface p-5 shadow-sm">
        <div className="flex items-start justify-between gap-3">
            <p className="text-xs font-semibold uppercase tracking-wide text-ink-muted">{exam.kind}</p>
            <StatusBadge tone={state.tone}>{state.label}</StatusBadge>
        </div>
        <h3 className="mt-2 text-lg font-semibold leading-snug">{exam.title}</h3>
        <p className="text-sm text-ink-muted">{exam.subject}</p>
        <dl className="mt-4 grid grid-cols-2 gap-3 rounded-lg bg-surface-muted p-3 text-sm">
            <div><dt className="text-xs text-ink-muted">Time limit</dt><dd className="mt-0.5 flex items-center gap-1.5 font-medium"><Clock3 className="size-4" aria-hidden="true" />{exam.durationMinutes} min</dd></div>
            <div><dt className="text-xs text-ink-muted">Attempts</dt><dd className="mt-0.5 font-medium">{exam.attemptsUsed} of {exam.attemptLimit} used</dd></div>
            <div className="col-span-2"><dt className="text-xs text-ink-muted">{upcoming ? 'Opens' : 'Closes'}</dt><dd className="mt-0.5 font-medium">{upcoming ? dates.dateTime(exam.opensAt) : exam.closesAt ? dates.dateTime(exam.closesAt) : 'No closing date'}</dd></div>
        </dl>
        <div className="mt-auto pt-4"><ButtonLink size="lg" className="w-full" variant={upcoming || exhausted ? 'secondary' : 'primary'} href={exam.resumeId && !upcoming ? `/portal/attempts/${exam.resumeId}` : `/portal/examinations/${exam.id}`}>{upcoming ? 'View Details' : exam.resumeId ? 'Continue Examination' : exhausted ? 'View Attempts' : exam.attemptsUsed > 0 ? 'View and Try Again' : 'View and Start'}</ButtonLink></div>
    </article>;
}
