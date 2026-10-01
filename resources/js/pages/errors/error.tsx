import { Head, usePage } from '@inertiajs/react';
import { Ban, CircleAlert, FileQuestion, Wrench, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { ButtonLink } from '@/components/ui/button';
import { cn } from '@/lib/cn';
import { routes } from '@/lib/routes';
import type { SharedProps } from '@/types';

type ErrorStatus = 403 | 404 | 500 | 503;

const content: Record<ErrorStatus, { icon: LucideIcon; title: string; description: string }> = {
    403: {
        icon: Ban,
        title: 'You do not have access to this page',
        description: 'Your account does not have permission to view this page. If you believe this is a mistake, contact an administrator.',
    },
    404: {
        icon: FileQuestion,
        title: 'Page not found',
        description: 'The page you requested does not exist or may have been moved.',
    },
    500: {
        icon: CircleAlert,
        title: 'Something went wrong',
        description: 'The system could not complete your request. Your saved work has not been affected. Try again, and contact an administrator if the problem continues.',
    },
    503: {
        icon: Wrench,
        title: 'The system is temporarily unavailable',
        description: 'Maintenance is in progress. Try again in a few minutes.',
    },
};

interface ErrorPageProps {
    status: ErrorStatus;
}

export default function ErrorPage({ status }: ErrorPageProps) {
    // Server errors are rendered without shared data, so read it defensively.
    const props = usePage().props as Partial<SharedProps>;
    const standalone = props.app === undefined;
    const signedIn = Boolean(props.auth?.user);
    const { icon: Icon, title, description } = content[status] ?? content[500];

    return (
        <>
            <Head title={title} />

            {/* Without a layout (server errors) this page provides its own main landmark. */}
            <ErrorBody standalone={standalone}>
                <div className="mb-4 flex size-12 items-center justify-center rounded-full bg-neutral-bg text-ink-muted">
                    <Icon className="size-6" aria-hidden="true" />
                </div>
                <p className="text-sm font-medium text-ink-muted tabular-nums">Error {status}</p>
                <h1 className="mt-1 text-xl font-semibold text-ink">{title}</h1>
                <p className="mt-2 max-w-md text-sm text-ink-muted">{description}</p>
                <div className="mt-6">
                    <ButtonLink href={signedIn || standalone ? routes.home() : routes.login()} variant="primary">
                        {signedIn || standalone ? 'Go to Home' : 'Go to Sign In'}
                    </ButtonLink>
                </div>
            </ErrorBody>
        </>
    );
}

function ErrorBody({ standalone, children }: { standalone: boolean; children: ReactNode }) {
    const classes = cn('flex flex-col items-center text-center', standalone ? 'min-h-dvh justify-center px-4 py-12' : 'py-10');

    return standalone ? (
        <main id="main-content" className={classes}>
            {children}
        </main>
    ) : (
        <div className={classes}>{children}</div>
    );
}
