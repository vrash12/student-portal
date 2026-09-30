import { Link, usePage } from '@inertiajs/react';
import { House, LogOut, UserRound } from 'lucide-react';
import { useEffect, type ReactNode } from 'react';
import { BrandMark } from '@/components/brand-mark';
import { buttonClasses } from '@/components/ui/button';
import { Toaster } from '@/components/ui/toaster';
import { routes } from '@/lib/routes';

/**
 * Candidate examination portal shell (UI_UX_DESIGN.md Â§20): deliberately
 * simpler than the staff area, with no administrative navigation.
 */
export default function CandidateLayout({ children }: { children: ReactNode }) {
    const { app, auth } = usePage().props;
    const { component } = usePage();
    useEffect(() => {
        if ('serviceWorker' in navigator && window.isSecureContext) {
            void navigator.serviceWorker.register('/portal-sw.js', { scope: '/portal' }).catch(() => undefined);
        }
    }, []);

    return (
        <div className="flex min-h-dvh flex-col">
            <a href="#main-content" className="sr-only rounded-lg bg-accent-300 px-4 py-3 text-primary-900 focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50">Skip to main content</a>
            <header className="brand-dark border-b-4 border-accent-300 bg-primary-900 text-white shadow-sm">
                <div className="mx-auto flex min-h-20 w-full max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-2 sm:px-6">
                    <div className="flex min-w-0 items-center gap-3">
                        <BrandMark className="size-12" />
                        <div className="min-w-0">
                            <p className="truncate text-sm font-semibold text-white">{app.shortName}</p>
                            <p className="truncate text-xs text-primary-100">{app.organizationName}</p>
                        </div>
                    </div>

                    <div className="ml-auto flex items-center gap-3 sm:gap-4">
                        {auth.user && (
                            <div className="hidden min-w-0 text-right sm:block">
                                <p className="truncate text-sm font-semibold text-white">{auth.user.name}</p>
                                <p className="truncate text-xs text-primary-100">{auth.user.username}</p>
                            </div>
                        )}
                        <Link
                            href={routes.logout()}
                            method="post"
                            as="button"
                            className={buttonClasses('ghost', 'md', 'text-primary-100 hover:bg-white/10 hover:text-white')}
                        >
                            <LogOut className="size-4" aria-hidden="true" />
                            Sign Out
                        </Link>
                    </div>
                </div>
                {component !== 'portal/examinations/attempt' && <nav aria-label="Candidate portal" className="mx-auto flex max-w-7xl gap-2 px-4 pb-3 sm:px-6">
                    {[{ href: '/portal', label: 'My Home', icon: House, active: component !== 'portal/profile' }, { href: '/portal/profile', label: 'My Information', icon: UserRound, active: component === 'portal/profile' }].map((item) =>
                        <Link key={item.href} href={item.href} aria-current={item.active ? 'page' : undefined} className={`inline-flex min-h-12 items-center gap-2 rounded-lg px-4 text-sm font-medium focus-visible:outline focus-visible:outline-2 ${item.active ? 'bg-accent-300 text-primary-900 shadow-sm' : 'text-primary-100 hover:bg-white/10'}`}><item.icon className="size-4" aria-hidden="true" />{item.label}</Link>)}
                </nav>}
            </header>

            <main id="main-content" tabIndex={-1} className={`mx-auto w-full ${component === 'portal/examinations/attempt' ? 'max-w-5xl' : 'max-w-7xl'} flex-1 px-4 py-8 sm:px-6`}>
                {children}
            </main>

            {component !== 'portal/examinations/attempt' && <footer className="mx-auto flex w-full max-w-7xl flex-wrap justify-between gap-2 border-t border-line px-4 py-5 text-xs text-ink-muted sm:px-6"><span>{app.shortName} · Candidate Portal</span><span>For record corrections, contact the academic office.</span></footer>}
            <Toaster position="top" />
        </div>
    );
}
