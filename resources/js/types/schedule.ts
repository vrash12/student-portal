/** What a calendar item is: a schedule entry, or a record read from its own module. */
export type ScheduleItemKind = 'session' | 'examination' | 'fitness' | 'attendance';

/** One item on a day (ScheduleCalendar; the portal sends a reduced set of fields). */
export interface ScheduleItem {
    kind: ScheduleItemKind;
    /** Staff pages: the entry, examination, test or session id. */
    id?: number;
    /** Portal: a key unique on the page. */
    key?: string;
    title: string;
    /** "HH:MM" in the institution's time; null for all-day items. */
    start: string | null;
    end: string | null;
    subject: string | null;
    instructor: string | null;
    location: string | null;
    className?: string | null;
    notes: string | null;
    repeatsWeekly?: boolean;
    /** Examinations: when they close (ISO). */
    closesAt: string | null;
    /** The item's page, when the user may open it. */
    href: string | null;
}

export interface ScheduleDay {
    /** Y-m-d. */
    date: string;
    items: ScheduleItem[];
}

/** A Monday-to-Sunday week (Y-m-d dates in the institution's timezone). */
export interface ScheduleWeekInfo {
    start: string;
    end: string;
    previous: string;
    next: string;
    today: string;
    isCurrent: boolean;
}
