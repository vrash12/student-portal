import { Link } from '@inertiajs/react';
import { CalendarClock, ChevronLeft, ChevronRight, ClipboardCheck, Dumbbell, MapPin, Repeat, UserRound } from 'lucide-react';
import { buttonClasses } from '@/components/ui/button';
import { cn } from '@/lib/cn';
import { formatCalendarDate, useDateFormatter } from '@/lib/format';
import type { ScheduleDay, ScheduleItem, ScheduleItemKind, ScheduleWeekInfo } from '@/types/schedule';

const weekdayFormat = new Intl.DateTimeFormat('en-US', { weekday: 'long', timeZone: 'UTC' });
const shortDateFormat = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' });

function utcDate(ymd: string): Date {
    return new Date(`${ymd}T00:00:00Z`);
}

/** "Monday" for a calendar date, without any timezone shift. */
export function weekdayName(ymd: string): string {
    return weekdayFormat.format(utcDate(ymd));
}

/** "Oct 5 – Oct 11, 2026". */
export function weekLabel(week: ScheduleWeekInfo): string {
    return `${shortDateFormat.format(utcDate(week.start))} – ${formatCalendarDate(week.end)}`;
}

/** Text label of each kind: never shown by colour alone. */
const KIND_LABELS: Record<ScheduleItemKind, string | null> = {
    session: null,
    examination: 'Examination',
    fitness: 'Fitness Test',
    attendance: 'Attendance',
};

const KIND_STYLES: Record<ScheduleItemKind, string> = {
    session: 'border-l-primary-600',
    examination: 'border-l-accent-400 bg-accent-50/60',
    fitness: 'border-l-info-fg bg-info-bg/40',
    attendance: 'border-l-line-strong bg-surface-muted/60',
};

function timeLabel(item: ScheduleItem): string {
    if (item.start === null) {
        return 'All day';
    }

    return item.end === null ? item.start : `${item.start}–${item.end}`;
}

/** Previous / This Week / Next, with the week's dates. */
export function WeekNavigation({ week, hrefFor }: { week: ScheduleWeekInfo; hrefFor: (date: string) => string }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="text-lg font-semibold text-ink" aria-live="polite">
                {weekLabel(week)}
                {week.isCurrent && <span className="ml-2 text-sm font-medium text-ink-muted">(this week)</span>}
            </p>
            <nav aria-label="Weeks" className="flex flex-wrap gap-2">
                <Link href={hrefFor(week.previous)} preserveScroll className={buttonClasses('secondary', 'md')}>
                    <ChevronLeft className="size-4" aria-hidden="true" />
                    Previous Week
                </Link>
                {!week.isCurrent && (
                    <Link href={hrefFor(week.today)} preserveScroll className={buttonClasses('secondary', 'md')}>
                        This Week
                    </Link>
                )}
                <Link href={hrefFor(week.next)} preserveScroll className={buttonClasses('secondary', 'md')}>
                    Next Week
                    <ChevronRight className="size-4" aria-hidden="true" />
                </Link>
            </nav>
        </div>
    );
}

interface WeekCalendarProps {
    days: ScheduleDay[];
    /** Today (Y-m-d), highlighted. */
    today: string;
    /** Show each item's class (an instructor's own week spans classes). */
    showClass?: boolean;
    /** "week": columns of days (seven from `sevenColumnsFrom`); "list": one day under another. */
    layout?: 'week' | 'list';
    /** Pages with the staff sidebar need more width for seven columns. */
    sevenColumnsFrom?: 'xl' | '2xl';
}

/**
 * The days of a week with their items, all-day items first, then by time.
 * Every kind is labelled in text; colour only adds emphasis.
 */
export function WeekCalendar({ days, today, showClass = false, layout = 'week', sevenColumnsFrom = 'xl' }: WeekCalendarProps) {
    const columns = sevenColumnsFrom === 'xl' ? 'md:grid-cols-2 xl:grid-cols-7' : 'md:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-7';

    return (
        <ol className={cn('grid gap-3', layout === 'week' ? columns : '')}>
            {days.map((day) => {
                const isToday = day.date === today;

                return (
                    <li
                        key={day.date}
                        aria-label={`${weekdayName(day.date)}, ${formatCalendarDate(day.date)}`}
                        className={cn('flex min-w-0 flex-col rounded-lg border bg-surface', isToday ? 'border-primary-600 ring-1 ring-primary-600' : 'border-line-box')}
                    >
                        <div className={cn('flex items-baseline justify-between gap-2 border-b px-3 py-2', isToday ? 'border-primary-200 bg-primary-50' : 'border-line')}>
                            <p className="text-sm font-semibold text-ink">{weekdayName(day.date)}</p>
                            <p className="text-xs text-ink-muted">
                                {isToday ? <span className="font-semibold text-primary-700">Today · </span> : null}
                                {shortDateFormat.format(utcDate(day.date))}
                            </p>
                        </div>
                        {day.items.length === 0 ? (
                            <p className="px-3 py-4 text-sm text-ink-muted">Nothing scheduled.</p>
                        ) : (
                            <ul className="flex flex-col gap-2 p-2">
                                {day.items.map((item) => (
                                    <li key={item.key ?? `${item.kind}-${item.id ?? item.title}`}>
                                        <ScheduleItemCard item={item} showClass={showClass} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </li>
                );
            })}
        </ol>
    );
}

function ScheduleItemCard({ item, showClass }: { item: ScheduleItem; showClass: boolean }) {
    const dates = useDateFormatter();
    const kindLabel = KIND_LABELS[item.kind];
    const details = [item.subject, showClass ? item.className : null].filter((value): value is string => value !== null && value !== undefined && value !== '');
    const body = (
        <>
            <p className="flex items-center gap-1.5 text-xs font-semibold tabular-nums text-primary-800">
                <CalendarClock className="size-3.5 shrink-0" aria-hidden="true" />
                {timeLabel(item)}
                {item.repeatsWeekly && (
                    <span className="inline-flex items-center gap-0.5 font-normal text-ink-muted" title="Every week">
                        <Repeat className="size-3" aria-hidden="true" />
                        <span className="sr-only">Every week</span>
                    </span>
                )}
            </p>
            {kindLabel !== null && (
                <p className="mt-1 inline-flex items-center gap-1 text-xs font-semibold uppercase tracking-wide text-ink-muted">
                    {item.kind === 'examination' ? <ClipboardCheck className="size-3" aria-hidden="true" /> : item.kind === 'fitness' ? <Dumbbell className="size-3" aria-hidden="true" /> : null}
                    {kindLabel}
                </p>
            )}
            <p className="mt-0.5 break-words text-sm font-semibold text-ink">{item.title}</p>
            {details.length > 0 && <p className="break-words text-xs text-ink-muted">{details.join(' · ')}</p>}
            {item.instructor !== null && (
                <p className="mt-1 flex items-center gap-1 text-xs text-ink">
                    <UserRound className="size-3 shrink-0 text-ink-muted" aria-hidden="true" />
                    <span className="break-words">{item.instructor}</span>
                </p>
            )}
            {item.location !== null && (
                <p className="flex items-center gap-1 text-xs text-ink">
                    <MapPin className="size-3 shrink-0 text-ink-muted" aria-hidden="true" />
                    <span className="break-words">{item.location}</span>
                </p>
            )}
            {item.closesAt !== null && item.end === null && <p className="text-xs text-ink-muted">Closes {dates.dateTime(item.closesAt)}</p>}
            {item.notes !== null && <p className="mt-1 whitespace-pre-line break-words text-xs text-ink-muted">{item.notes}</p>}
        </>
    );
    const classes = cn('block rounded-md border border-line border-l-4 px-2.5 py-2', KIND_STYLES[item.kind]);

    return item.href !== null ? (
        <Link
            href={item.href}
            className={cn(classes, 'transition-colors hover:border-primary-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 motion-reduce:transition-none')}
        >
            {body}
        </Link>
    ) : (
        <div className={classes}>{body}</div>
    );
}
