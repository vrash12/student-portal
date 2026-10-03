import { CalendarClock, CircleCheck, Clock3, PlayCircle, type LucideIcon } from 'lucide-react';
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
    resumeId: number | null;
}

/**
 * One examination with the single action that fits its state: start,
 * continue, or view. Each examination is taken once (no retakes). The state
 * is always written out with an icon (never colour alone).
 */
export function ExamCard({ exam, upcoming = false }: { exam: PortalExam; upcoming?: boolean }) {
    const dates = useDateFormatter();
    const taken = exam.attemptsUsed > 0;
    // "Open now" and "Scheduled" are already the titles of the sections the
    // cards sit in; only the states that differ from the section get a badge.
    const state: { tone: StatusTone; label: string } | null = upcoming
        ? null
        : exam.resumeId
          ? { tone: 'warning', label: 'In progress' }
          : taken
            ? { tone: 'neutral', label: 'Taken' }
            : null;
    const action: { label: string; icon: LucideIcon; href: string; primary: boolean } = upcoming
        ? { label: 'View Details', icon: CalendarClock, href: routes.portal.examination(exam.id), primary: false }
        : exam.resumeId
          ? { label: 'Continue Examination', icon: PlayCircle, href: routes.portal.attempt(exam.resumeId), primary: true }
          : taken
            ? { label: 'View Details', icon: CircleCheck, href: routes.portal.examination(exam.id), primary: false }
            : { label: 'Start', icon: PlayCircle, href: routes.portal.examination(exam.id), primary: true };

    return (
        <article className="flex flex-col rounded-2xl border border-line-box bg-surface p-6 shadow-sm">
            <div className="flex items-center justify-between gap-3">
                <span className="text-xs font-bold uppercase tracking-wider text-ink-muted">{exam.kind}</span>
                {state !== null && <StatusBadge tone={state.tone}>{state.label}</StatusBadge>}
            </div>
            <h3 className="mt-3 text-xl font-semibold leading-snug text-primary-900">{exam.title}</h3>
            <p className="mt-1 text-base text-ink-muted">{exam.subject}</p>
            <ul className="mt-5 flex flex-col gap-2.5 text-sm text-ink">
                <li className="flex items-center gap-2.5">
                    <Clock3 className="size-5 text-primary-600" aria-hidden="true" />
                    {exam.durationMinutes} minutes
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
