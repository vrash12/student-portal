import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

const SEARCH_DEBOUNCE_MS = 300;

/**
 * Keeps list filters in the query string so they survive pagination and
 * refreshes. The server re-validates every filter value.
 */
export function useQueryFilters<TFilters extends Record<string, string>>(url: string, initial: TFilters) {
    const [values, setValues] = useState<TFilters>(initial);
    const latest = useRef<TFilters>(initial);
    const timer = useRef<number | undefined>(undefined);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    const visit = useCallback(
        (filters: TFilters) => {
            const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));
            router.get(url, query, { preserveState: true, preserveScroll: true, replace: true });
        },
        [url],
    );

    const apply = useCallback(
        (next: TFilters, debounce: boolean) => {
            latest.current = next;
            setValues(next);
            window.clearTimeout(timer.current);

            if (debounce) {
                timer.current = window.setTimeout(() => visit(next), SEARCH_DEBOUNCE_MS);
            } else {
                visit(next);
            }
        },
        [visit],
    );

    const update = useCallback(
        <TKey extends keyof TFilters>(key: TKey, value: TFilters[TKey], options: { debounce?: boolean } = {}) => {
            apply({ ...latest.current, [key]: value }, options.debounce ?? false);
        },
        [apply],
    );

    /** Changes several filters in one visit, e.g. a class and the subject that depends on it. */
    const updateMany = useCallback(
        (patch: Partial<TFilters>, options: { debounce?: boolean } = {}) => {
            apply({ ...latest.current, ...patch }, options.debounce ?? false);
        },
        [apply],
    );

    const reset = useCallback(() => {
        const cleared = Object.fromEntries(Object.keys(latest.current).map((key) => [key, ''])) as TFilters;
        apply(cleared, false);
    }, [apply]);

    const isFiltered = Object.values(values).some((value) => value !== '');

    return { values, update, updateMany, reset, isFiltered };
}
