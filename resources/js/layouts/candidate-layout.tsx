import { Link, usePage } from '@inertiajs/react';
import { Award, ClipboardList, Dumbbell, GraduationCap, House, LogOut, UserRound, type LucideIcon } from 'lucide-react';
import { useEffect, type ReactNode } from 'react';
import { BrandMark } from '@/components/brand-mark';
import { buttonClasses } from '@/components/ui/button';
import { Toaster } from '@/components/ui/toaster';
import { pruneRecovery, setRecoveryOwner } from '@/lib/exam-recovery';
import { routes } from '@/lib/routes';
import { useFocusMainOnNavigate } from '@/lib/use-focus-main-on-navigate';

/** The portal's pages, in navigation order; each is active for its own page components. */
const PORTAL_SECTIONS: Array<{ href: string; label: string; icon: LucideIcon; isActive: (component: string) => boolean }> = [
    { href: routes.portal.home(), label: 'Home', icon: House, isActive: (component) => component === 'portal/home' },
    { href: routes.portal.examinations(), label: 'Examinations', icon: ClipboardList, isActive: (component) => component.startsWith('portal/examinations/') },
    { href: routes.portal.grades(), label: 'My Grades', icon: GraduationCap, isActive: (component) => component === 'portal/grades' },
    { href: routes.portal.performance(), label: 'My Performance', icon: Award, isActive: (component) => component === 'portal/performance' },
    { href: routes.portal.fitness(), label: 'Physical Fitness', icon: Dumbbell, isActive: (component) => component === 'portal/fitness' },
    { href: routes.portal.profile(), label: 'My Information', icon: UserRound, isActive: (component) => component === 'portal/profile' },
];

/**
 * Candidate examination portal shell (UI_UX_DESIGN.md §20): deliberately
 * simpler than the staff area, with no administrative navigation.
 */
export default function CandidateLayout({ children }: { children: ReactNode }) {
    const { app, auth } = usePage().props;
    const { component } = usePage();
    const userId = auth.user?.id ?? null;
    useFocusMainOnNavigate();
    // Set during render: child pages (the attempt screen) read recovery data
    // in their own effects, which run before this layout's effects.
    setRecoveryOwner(userId);
    useEffect(() => {
        void pruneRecovery();
    }, [userId]);
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
                        {/* Hidden during an attempt: one tap would sign the candidate out mid-examination. */}
                        {component !== 'portal/examinations/attempt' && <Link
                            href={routes.logout()}
                            method="post"
                            as="button"
                            onBefore={() => { void pruneRecovery(); }}
                            className={buttonClasses('ghost', 'md', 'text-primary-100 hover:bg-white/10 hover:text-white')}
                        >
                            <LogOut className="size-4" aria-hidden="true" />
                            Sign Out
                        </Link>}
                    </div>
                </div>
                {component !== 'portal/examinations/attempt' && <nav aria-label="Candidate portal" className="mx-auto max-w-7xl px-2 pb-2 sm:px-4">
                    {/* Equal tabs, icon above the label: one row of six from tablet width, two rows of three on phones. Nothing is hidden or scrolled. */}
                    <ul className="grid grid-cols-3 gap-1 sm:grid-cols-6">
                        {PORTAL_SECTIONS.map((item) => {
                            const active = item.isActive(component);

                            return <li key={item.href}>
                                <Link href={item.href} aria-current={active ? 'page' : undefined} className={`flex min-h-16 flex-col items-center justify-center gap-1 rounded-xl px-2 py-2 text-center text-xs font-semibold leading-tight sm:text-sm focus-visible:outline focus-visible:outline-2 ${active ? 'bg-accent-300 text-primary-900 shadow-sm' : 'text-primary-100 hover:bg-white/10'}`}>
                                    <item.icon className="size-5 shrink-0" aria-hidden="true" />{item.label}
                                </Link>
                            </li>;
                        })}
                    </ul>
                </nav>}
            </header>

            <main id="main-content" tabIndex={-1} className="mx-auto w-full max-w-6xl flex-1 px-4 py-8 sm:px-6 sm:py-10">
                {children}
            </main>

            {component !== 'portal/examinations/attempt' && <footer className="mx-auto flex w-full max-w-7xl flex-wrap justify-between gap-2 border-t border-line px-4 py-5 text-xs text-ink-muted sm:px-6"><span>{app.shortName} · Candidate Portal</span><span>For record corrections, contact the academic office.</span></footer>}
            <Toaster position="top" />
        </div>
    );
}
