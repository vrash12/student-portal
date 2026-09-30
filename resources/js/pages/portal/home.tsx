import { Head } from '@inertiajs/react';
import { ClipboardList, Clock3 } from 'lucide-react';
import { ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { Pagination } from '@/components/ui/pagination';
import { StatusBadge } from '@/components/ui/status-badge';
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
        <PageHeader title={`Welcome, ${summary.name}`} description={`Candidate ${summary.number} · ${summary.className ?? 'Class not assigned'}${summary.period ? ` · ${summary.period}` : ''}`} actions={<ButtonLink href="/portal/profile">My Information</ButtonLink>} />
        <div className="flex flex-col gap-6">
            <Panel title="Academic Summary" description="Current standing from finalized assessments in your assigned class.">
                <div className="grid gap-5 sm:grid-cols-3">
                    <div><p className="mb-1 text-sm text-ink-muted">Overall standing</p>{summary.overall.standing ? <OverallStandingValue overall={summary.overall} /> : <p className="text-sm">Not available yet</p>}</div>
                    <div><p className="text-sm text-ink-muted">Enrolled subjects</p><p className="mt-1 text-xl font-semibold tabular-nums">{summary.subjectCount}</p></div>
                    <div><p className="text-sm text-ink-muted">Assessments awaiting a score</p><p className="mt-1 text-xl font-semibold tabular-nums">{outstanding.total}</p></div>
                </div>
                {!summary.eligible && <p className="mt-4 text-sm text-ink-muted">An eligible class assignment is required to take examinations. Contact the academic office to review your enrollment.</p>}
            </Panel>
            <Panel title="Available Examinations" description="Start an open assessment, continue an attempt or review your submissions." bodyClassName="p-0">
                {available.data.length === 0 ? <div className="p-5"><EmptyState icon={ClipboardList} headingLevel="h3" title="No examinations open now" description="Scheduled examinations are listed below when your instructor publishes them." /></div> : <div className="grid gap-4 p-5 sm:grid-cols-2">{available.data.map((exam) => <ExamCard key={exam.id} exam={exam} />)}</div>}
                <Pagination page={available} noun={{ one: 'examination', other: 'examinations' }} />
            </Panel>
            <Panel title="Upcoming Examinations" description="Published assessments scheduled for your class. Times use the institution’s timezone." bodyClassName="p-0">
                {upcoming.data.length === 0 ? <p className="p-5 text-sm text-ink-muted">No upcoming examinations scheduled.</p> : <div className="grid gap-4 p-5 sm:grid-cols-2">{upcoming.data.map((exam) => <ExamCard key={exam.id} exam={exam} upcoming />)}</div>}
                <Pagination page={upcoming} noun={{ one: 'scheduled examination', other: 'scheduled examinations' }} />
            </Panel>
            <div className="grid items-start gap-6 lg:grid-cols-2">
                <Panel title="Outstanding Assessments" description="Finalized assessments with no recorded score. Work may be outstanding or grading may still be pending; check with your instructor." bodyClassName="p-0">
                    {outstanding.data.length === 0 ? <p className="p-5 text-sm text-ink-muted">No finalized assessments are missing a score.</p> : <ul className="divide-y divide-line">{outstanding.data.map((item) => <li key={item.id} className="p-5"><p className="font-semibold">{item.title}</p><p className="mt-1 text-sm text-ink-muted">{item.subject} · {item.category}</p>{item.date && <p className="text-sm text-ink-muted">Assessment date: {formatCalendarDate(item.date)}</p>}<div className="mt-2"><StatusBadge tone="warning">Awaiting score</StatusBadge></div></li>)}</ul>}
                    <Pagination page={outstanding} noun={{ one: 'assessment', other: 'assessments' }} />
                </Panel>
                <Panel title="Recent Released Results" description="Your six most recent graded submissions whose results have been released." bodyClassName="p-0">
                    {recentResults.length === 0 ? <p className="p-5 text-sm text-ink-muted">No released results yet. Results appear after grading and instructor release.</p> : <ul className="divide-y divide-line">{recentResults.map((result) => <li key={result.id} className="p-5"><div className="flex items-start justify-between gap-3"><div><p className="font-semibold">{result.title}</p><p className="mt-1 text-sm text-ink-muted">{result.subject} · {result.kind}</p></div><span className="font-semibold tabular-nums">{formatPercent(result.percentage)}</span></div><p className="mt-1 text-xs text-ink-muted">Attempt {result.attemptNumber} · Submitted {dates.dateTime(result.submittedAt)}</p>{result.passed !== null && <div className="mt-2"><StatusBadge tone={result.passed ? 'success' : 'danger'}>{result.passed ? 'Passed' : 'Failed'}</StatusBadge></div>}</li>)}</ul>}
                    <div className="border-t border-line p-4"><ButtonLink href="/portal/profile" variant="secondary">View Academic Record</ButtonLink></div>
                </Panel>
            </div>
        </div>
    </>;
}

function ExamCard({ exam, upcoming = false }: { exam: Exam; upcoming?: boolean }) {
    const dates = useDateFormatter();
    const exhausted = exam.attemptsUsed >= exam.attemptLimit;
    return <article className="flex flex-col rounded-lg border border-line p-4">
        <p className="text-xs font-medium text-ink-muted">{exam.kind}</p><h3 className="mt-1 text-lg font-semibold">{exam.title}</h3><p className="text-sm text-ink-muted">{exam.subject}</p>
        <p className="mt-3 flex items-center gap-2 text-sm"><Clock3 className="size-4" aria-hidden="true" />{exam.durationMinutes} minute limit</p>
        <p className="mt-1 text-sm text-ink-muted">{upcoming ? `Opens ${dates.dateTime(exam.opensAt)}` : exam.closesAt ? `Closes ${dates.dateTime(exam.closesAt)}` : 'No closing date scheduled'}</p>
        <p className="mt-1 text-sm text-ink-muted">{exam.attemptsUsed} of {exam.attemptLimit} attempts used</p>
        <div className="mt-4"><ButtonLink size="lg" variant={upcoming || exhausted ? 'secondary' : 'primary'} href={exam.resumeId && !upcoming ? `/portal/attempts/${exam.resumeId}` : `/portal/examinations/${exam.id}`}>{upcoming ? 'View Details' : exam.resumeId ? 'Continue Examination' : exhausted ? 'View Attempts' : exam.attemptsUsed > 0 ? 'Review / Try Again' : 'View & Start'}</ButtonLink></div>
    </article>;
}
