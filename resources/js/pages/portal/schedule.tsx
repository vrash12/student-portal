import { Head } from '@inertiajs/react';
import { CalendarDays } from 'lucide-react';
import { PortalEmpty, PortalHeading } from '@/components/portal/portal-ui';
import { WeekCalendar, WeekNavigation } from '@/components/schedule/week-calendar';
import { routes } from '@/lib/routes';
import type { ScheduleDay, ScheduleWeekInfo } from '@/types/schedule';

interface PortalScheduleProps {
    week: ScheduleWeekInfo;
    days: ScheduleDay[];
    /** The candidate's class; null without one. */
    className: string | null;
}

/** The candidate's week: their class's sessions and examinations (owner request, 2026-10-06). */
export default function PortalSchedule({ week, days, className }: PortalScheduleProps) {
    const itemCount = days.reduce((total, day) => total + day.items.length, 0);

    return (
        <>
            <Head title="Schedule" />

            <PortalHeading
                icon={CalendarDays}
                title="Schedule"
                description={className === null ? 'Your class schedule appears here once you are assigned to a class.' : `The week of ${className}: sessions, rooms and examinations.`}
            />

            <div className="flex flex-col gap-5">
                <WeekNavigation week={week} hrefFor={(date) => routes.portal.schedule({ week: date })} />
                {itemCount === 0 && (
                    <PortalEmpty icon={CalendarDays} title="Nothing scheduled this week">
                        {className === null ? 'Please contact the academic office about your class assignment.' : 'Check another week, or ask your instructor about the schedule.'}
                    </PortalEmpty>
                )}
                <WeekCalendar days={days} today={week.today} />
            </div>
        </>
    );
}
