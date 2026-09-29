import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';

const EMPTY_VALUE = '—';

const calendarDateFormat = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium', timeZone: 'UTC' });

/**
 * Formats a calendar date ("2026-08-03") without any timezone shift.
 */
export function formatCalendarDate(ymd: string | null): string {
    return ymd ? calendarDateFormat.format(new Date(`${ymd}T00:00:00Z`)) : EMPTY_VALUE;
}

/**
 * Formats ISO timestamps from the server in the institution's timezone.
 */
export function useDateFormatter(): {
    dateTime: (iso: string | null) => string;
    date: (iso: string | null) => string;
} {
    const { timezone } = usePage().props.app;

    return useMemo(() => {
        const dateTimeFormat = new Intl.DateTimeFormat('en-US', {
            dateStyle: 'medium',
            timeStyle: 'short',
            timeZone: timezone,
        });
        const dateFormat = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium', timeZone: timezone });

        return {
            dateTime: (iso) => (iso ? dateTimeFormat.format(new Date(iso)) : EMPTY_VALUE),
            date: (iso) => (iso ? dateFormat.format(new Date(iso)) : EMPTY_VALUE),
        };
    }, [timezone]);
}
