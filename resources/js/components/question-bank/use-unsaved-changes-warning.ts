import { router } from '@inertiajs/react';
import { useEffect } from 'react';

const MESSAGE = 'You have unsaved changes to this question. Leave this page and discard them?';

/**
 * While `active`, asks before leaving the page through a link, another action
 * (such as Duplicate), a reload, or closing the tab. The form's own
 * submission to `submitPath` is never interrupted. Browser Back/Forward is
 * not interrupted.
 */
export function useUnsavedChangesWarning(active: boolean, submitPath: string) {
    useEffect(() => {
        if (!active) {
            return;
        }

        const onBeforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
        };
        window.addEventListener('beforeunload', onBeforeUnload);

        const removeGuard = router.on('before', (event) => {
            const { visit } = event.detail;
            const isSubmission = visit.method !== 'get' && visit.url.pathname === submitPath;

            if (!isSubmission && !window.confirm(MESSAGE)) {
                event.preventDefault();
            }
        });

        return () => {
            window.removeEventListener('beforeunload', onBeforeUnload);
            removeGuard();
        };
    }, [active, submitPath]);
}
