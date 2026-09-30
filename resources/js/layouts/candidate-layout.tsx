import { Link, usePage } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import { useEffect, type ReactNode } from 'react';
import { BrandMark } from '@/components/brand-mark';
import { buttonClasses } from '@/components/ui/button';
import { Toaster } from '@/components/ui/toaster';
import { routes } from '@/lib/routes';

/**
 * Candidate examination portal shell (UI_UX_DESIGN.md §20): deliberately
 * simpler than the staff area, with no administrative navigation.
 */
export default function CandidateLayout({ children }: { children: ReactNode }) {
    const { app, auth } = usePage().props;
    useEffect(() => {
        if ('serviceWorker' in navigator && window.isSecureContext) {
            void navigator.serviceWorker.register('/portal-sw.js', { scope: '/portal' }).catch(() => undefined);
        }
    }, []);

    return (
        <div className="flex min-h-dvh flex-col">
            <header className="border-b border-line bg-surface">
                <div className="mx-auto flex min-h-16 w-full max-w-5xl items-center justify-between gap-4 px-4 py-2 sm:px-6">
                    <div className="flex min-w-0 items-center gap-3">
                        <BrandMark />
                        <div className="min-w-0">
                            <p className="truncate text-sm font-semibold text-ink">{app.shortName}</p>
                            <p className="truncate text-xs text-ink-muted">{app.organizationName}</p>
                        </div>
                    </div>

                    <div className="flex items-center gap-3 sm:gap-4">
                        {auth.user && (
                            <div className="min-w-0 text-right">
                                <p className="truncate text-sm font-semibold text-ink">{auth.user.name}</p>
                                <p className="truncate text-xs text-ink-muted">{auth.user.username}</p>
                            </div>
                        )}
                        <Link
                            href={routes.logout()}
                            method="post"
                            as="button"
                            className={buttonClasses('secondary', 'md')}
                        >
                            <LogOut className="size-4" aria-hidden="true" />
                            Sign Out
                        </Link>
                    </div>
                </div>
            </header>

            <main id="main-content" tabIndex={-1} className="mx-auto w-full max-w-5xl flex-1 px-4 py-8 sm:px-6">
                {children}
            </main>

            <Toaster position="top" />
        </div>
    );
}
