import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';

const EMPTY_VALUE = '—';

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
