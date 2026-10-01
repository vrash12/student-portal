import { router } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * After a visit to another page, moves keyboard and screen-reader focus to
 * the main content (#main-content, tabIndex -1), so the new page is
 * announced instead of focus staying on the link in the persistent layout
 * (UI_UX_DESIGN.md §68). Visits that stay on the same path (filters, sorting,
 * partial reloads such as live monitoring) keep focus where it is.
 */
export function useFocusMainOnNavigate(): void {
    useEffect(() => {
        let path = window.location.pathname;

        return router.on('navigate', () => {
            const next = window.location.pathname;
            if (next === path) {
                return;
            }
            path = next;
            document.getElementById('main-content')?.focus({ preventScroll: true });
        });
    }, []);
}
