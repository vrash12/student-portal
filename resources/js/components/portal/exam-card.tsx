import { CalendarClock, CircleCheck, Clock3, PlayCircle, Repeat, RotateCcw, type LucideIcon } from 'lucide-react';
import { ButtonLink } from '@/components/ui/button';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';

export interface PortalExam {
    id: number;
    title: string;
    kind: string;
    subject: string;
    durationMinutes: number;
    opensAt: string | null;
    closesAt: string | null;
    attemptsUsed: number;
    attemptLimit: number;
    resumeId: number | null;
}

/**
 * One examination with the single action that fits its state: start,
 * continue, try again, or view. The state is always written out with an
 * icon (never colour alone).
 */
export function ExamCard({ exam, upcoming = false }: { exam: PortalExam; upcoming?: boolean }) {
    const dates = useDateFormatter();
    const exhausted = exam.attemptsUsed >= exam.attemptLimit;
    const state: { tone: StatusTone; label: string } = upcoming
        ? { tone: 'neutral', label: 'Scheduled' }
        : exam.resumeId
          ? { tone: 'warning', label: 'In progress' }
          : exhausted
            ? { tone: 'neutral', label: 'All attempts used' }
            : { tone: 'success', label: 'Open now' };
    const action: { label: string; icon: LucideIcon; href: string; primary: boolean } = upcoming
        ? { label: 'View Details', icon: CalendarClock, href: routes.portal.examination(exam.id), primary: false }
        : exam.resumeId
          ? { label: 'Continue Examination', icon: PlayCircle, href: routes.portal.attempt(exam.resumeId), primary: true }
          : exhausted
            ? { label: 'View Attempts', icon: CircleCheck, href: routes.portal.examination(exam.id), primary: false }
            : exam.attemptsUsed > 0
              ? { label: 'Try Again', icon: RotateCcw, href: routes.portal.examination(exam.id), primary: true }
              : { label: 'Start', icon: PlayCircle, href: routes.portal.examination(exam.id), primary: true };

    return (
        <article className="flex flex-col rounded-2xl border border-line bg-surface p-6 shadow-sm">
            <div className="flex items-center justify-between gap-3">
                <span className="text-xs font-bold uppercase tracking-wider text-ink-muted">{exam.kind}</span>
                <StatusBadge tone={state.tone}>{state.label}</StatusBadge>
            </div>
            <h3 className="mt-3 text-xl font-semibold leading-snug text-primary-900">{exam.title}</h3>
            <p className="mt-1 text-base text-ink-muted">{exam.subject}</p>
            <ul className="mt-5 flex flex-col gap-2.5 text-sm text-ink">
                <li className="flex items-center gap-2.5">
                    <Clock3 className="size-5 text-primary-600" aria-hidden="true" />
                    {exam.durationMinutes} minutes
                </li>
                <li className="flex items-center gap-2.5">
                    <Repeat className="size-5 text-primary-600" aria-hidden="true" />
                    {exam.attemptsUsed} of {exam.attemptLimit} {exam.attemptLimit === 1 ? 'attempt' : 'attempts'} used
                </li>
                <li className="flex items-center gap-2.5">
                    <CalendarClock className="size-5 text-primary-600" aria-hidden="true" />
                    {upcoming ? `Opens ${dates.dateTime(exam.opensAt)}` : exam.closesAt ? `Closes ${dates.dateTime(exam.closesAt)}` : 'No closing date'}
                </li>
            </ul>
            <div className="mt-auto pt-6">
                <ButtonLink
                    size="lg"
                    className="w-full"
                    variant={action.primary ? 'primary' : 'secondary'}
                    href={action.href}
                    icon={<action.icon className="size-5" aria-hidden="true" />}
                >
                    {action.label}
                </ButtonLink>
            </div>
        </article>
    );
}
