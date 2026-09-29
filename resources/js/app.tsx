import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import AuthLayout from '@/layouts/auth-layout';
import CandidateLayout from '@/layouts/candidate-layout';
import StaffLayout from '@/layouts/staff-layout';
import { Permission } from '@/lib/permissions';
import type { SharedProps } from '@/types';

const FALLBACK_APP_NAME = 'Academic Monitoring System';

const pages = import.meta.glob<ResolvedComponent>('./pages/**/*.tsx', { import: 'default' });

createInertiaApp({
    title: (title, page) => {
        const appName = (page.props.app as SharedProps['app'] | undefined)?.name ?? FALLBACK_APP_NAME;

        return title ? `${title} · ${appName}` : appName;
    },

    resolve: (name) => {
        const page = pages[`./pages/${name}.tsx`];
        if (page === undefined) {
            throw new Error(`Inertia page not found: ${name}`);
        }

        return page();
    },

    // Default layouts by page area. Layout instances persist across visits
    // within the same area, so sidebar state is not lost on navigation.
    layout: (name, page) => {
        if (name.startsWith('staff/')) {
            return StaffLayout;
        }
        if (name.startsWith('portal/')) {
            return CandidateLayout;
        }
        if (name.startsWith('auth/')) {
            return AuthLayout;
        }
        if (name.startsWith('errors/')) {
            return errorLayout(page.props as Partial<SharedProps>);
        }

        return undefined;
    },

    setup({ el, App, props }) {
        if (el !== null) {
            createRoot(el).render(<App {...props} />);
        }
    },

    progress: {
        color: '#1f6b4a',
        delay: 250,
    },
});

/**
 * Error pages render inside the shell the user normally sees. Server errors
 * are rendered without shared data, so they fall back to a standalone page.
 */
function errorLayout(props: Partial<SharedProps>) {
    if (props.app === undefined || props.auth === undefined) {
        return undefined;
    }

    if (props.auth.permissions.includes(Permission.AccessStaffArea)) {
        return StaffLayout;
    }
    if (props.auth.permissions.includes(Permission.AccessExamPortal)) {
        return CandidateLayout;
    }

    return AuthLayout;
}
