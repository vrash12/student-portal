import { Link, usePage } from '@inertiajs/react';
import { Apple, Award, CalendarDays, ChevronDown, ChevronUp, ClipboardList, LayoutGrid, Dumbbell, GraduationCap, HeartPulse, House, LogOut, UserRound, type LucideIcon } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { BrandMark } from '@/components/brand-mark';
import { buttonClasses } from '@/components/ui/button';
import { Toaster } from '@/components/ui/toaster';
import { pruneRecovery, setRecoveryOwner } from '@/lib/exam-recovery';
import { routes } from '@/lib/routes';
import { useFocusMainOnNavigate } from '@/lib/use-focus-main-on-navigate';

/** The portal's pages, in navigation order; each is active for its own page components. */
/** One row of tabs from tablet width (static class names for Tailwind). */
// Eight or nine tabs: two rows on tablets, one row on wide screens.
const TAB_COLUMNS: Record<number, string> = { 5: 'sm:grid-cols-5', 6: 'sm:grid-cols-6', 7: 'sm:grid-cols-7', 8: 'sm:grid-cols-4 lg:grid-cols-8', 9: 'sm:grid-cols-5 lg:grid-cols-9' };

const PORTAL_SECTIONS: Array<{ href: string; label: string; icon: LucideIcon; isActive: (component: string) => boolean; fitness?: true }> = [
    { href: routes.portal.home(), label: 'Home', icon: House, isActive: (component) => component === 'portal/home' },
    { href: routes.portal.schedule(), label: 'Schedule', icon: CalendarDays, isActive: (component) => component === 'portal/schedule' },
    { href: routes.portal.examinations(), label: 'Examinations', icon: ClipboardList, isActive: (component) => component.startsWith('portal/examinations/') },
    { href: routes.portal.grades(), label: 'My Grades', icon: GraduationCap, isActive: (component) => component === 'portal/grades' },
    { href: routes.portal.performance(), label: 'My Performance', icon: Award, isActive: (component) => component === 'portal/performance' },
    { href: routes.portal.fitness(), label: 'Physical Fitness', icon: Dumbbell, isActive: (component) => component === 'portal/fitness', fitness: true },
    { href: routes.portal.medical(), label: 'Medical', icon: HeartPulse, isActive: (component) => component === 'portal/medical' },
    { href: routes.portal.nutrition(), label: 'Nutrition', icon: Apple, isActive: (component) => component === 'portal/nutrition' },
    { href: routes.portal.profile(), label: 'My Information', icon: UserRound, isActive: (component) => component === 'portal/profile' },
];

/** The candidate's picture, or their initial when there is none; decorative (the name is shown beside it). */
function CandidateAvatar({ name, photoUrl }: { name: string; photoUrl: string | null }) {
    const [failed, setFailed] = useState(false);

    return photoUrl !== null && !failed ? (
        <img src={photoUrl} alt="" onError={() => setFailed(true)} className="size-9 shrink-0 rounded-full object-cover ring-2 ring-accent-300 sm:size-10" />
    ) : (
        <span aria-hidden="true" className="flex size-9 shrink-0 items-center justify-center rounded-full bg-accent-300 text-sm font-bold text-primary-900 sm:size-10">
            {name.trim().charAt(0).toUpperCase() || '?'}
        </span>
    );
}

/** Remembers on this device whether the candidate folded the phone menu. */
const NAV_COLLAPSED_KEY = 'portal-nav-collapsed';

function readNavCollapsed(): boolean {
    try {
        return window.localStorage.getItem(NAV_COLLAPSED_KEY) === '1';
    } catch {
        return false;
    }
}

function storeNavCollapsed(collapsed: boolean): void {
    try {
        window.localStorage.setItem(NAV_COLLAPSED_KEY, collapsed ? '1' : '0');
    } catch {
        // Storage unavailable (private window): the choice lasts for this page only.
    }
}

/**
 * Candidate examination portal shell (UI_UX_DESIGN.md §20): deliberately
 * simpler than the staff area, with no administrative navigation.
 */
export default function CandidateLayout({ children }: { children: ReactNode }) {
    const { app, auth } = usePage().props;
    const { component } = usePage();
    const userId = auth.user?.id ?? null;
    // Military fitness is staff only unless the institution shows it to candidates.
    const sections = PORTAL_SECTIONS.filter((item) => !item.fitness || app.portal.showFitness);
    // Phones only: the icon grid can be folded into one bar (owner request, 2026-10-03).
    const [navCollapsed, setNavCollapsed] = useState(readNavCollapsed);
    const toggleNav = () => {
        setNavCollapsed((collapsed) => {
            storeNavCollapsed(!collapsed);

            return !collapsed;
        });
    };
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
                <div className="mx-auto flex min-h-16 w-full max-w-7xl items-center justify-between gap-2 px-3 py-2 sm:min-h-20 sm:gap-4 sm:px-6">
                    <div className="flex min-w-0 flex-1 items-center gap-2 sm:gap-3">
                        {/* Phones: the logo alone; the space goes to the candidate's name. */}
                        <BrandMark className="size-10 shrink-0 sm:size-12" />
                        <p className="hidden min-w-0 truncate text-sm font-semibold text-white sm:block">{app.organizationName}</p>
                    </div>

                    <div className="ml-auto flex shrink-0 items-center gap-1 sm:gap-4">
                        {auth.user && (
                            <div className="flex min-w-0 items-center gap-2 pr-1 sm:gap-3">
                                <div className="min-w-0 text-right">
                                    <p className="max-w-36 truncate text-sm font-semibold text-white sm:max-w-48">{auth.user.candidate?.firstName ?? auth.user.name}</p>
                                    <p className="hidden truncate text-xs text-primary-100 sm:block">{auth.user.username}</p>
                                </div>
                                <CandidateAvatar name={auth.user.candidate?.firstName ?? auth.user.name} photoUrl={auth.user.candidate?.photoUrl ?? null} />
                            </div>
                        )}
                        {/* Phones: a small button folds or shows the page icons below (owner request, 2026-10-03). Hidden from tablet width and during an attempt. */}
                        {component !== 'portal/examinations/attempt' && <button
                            type="button"
                            onClick={toggleNav}
                            aria-expanded={!navCollapsed}
                            aria-controls="portal-sections"
                            aria-label={navCollapsed ? 'Show menu' : 'Hide menu'}
                            title={navCollapsed ? 'Show menu' : 'Hide menu'}
                            className={`inline-flex min-h-11 items-center gap-0.5 rounded-lg px-2.5 focus-visible:outline focus-visible:outline-2 sm:hidden ${navCollapsed ? 'bg-white/10 text-white hover:bg-white/15' : 'text-primary-100 hover:bg-white/10 hover:text-white'}`}
                        >
                            <LayoutGrid className="size-5" aria-hidden="true" />
                            {navCollapsed ? <ChevronDown className="size-4" aria-hidden="true" /> : <ChevronUp className="size-4" aria-hidden="true" />}
                        </button>}
                        {/* Hidden during an attempt: one tap would sign the candidate out mid-examination. */}
                        {component !== 'portal/examinations/attempt' && <Link
                            href={routes.logout()}
                            method="post"
                            as="button"
                            onBefore={() => { void pruneRecovery(); }}
                            aria-label="Sign Out"
                            className={buttonClasses('ghost', 'md', 'px-2.5 text-primary-100 hover:bg-white/10 hover:text-white sm:px-4')}
                        >
                            <LogOut className="size-4" aria-hidden="true" />
                            <span className="hidden sm:inline">Sign Out</span>
                        </Link>}
                    </div>
                </div>
                {component !== 'portal/examinations/attempt' && <nav aria-label="Candidate portal" className="mx-auto max-w-7xl px-2 pb-2 sm:px-4">
                    {/* Equal tabs, icon above the label: one row from tablet width, rows of three on phones. */}
                    <ul id="portal-sections" className={`${navCollapsed ? 'hidden sm:grid' : 'grid'} grid-cols-3 gap-1 ${TAB_COLUMNS[sections.length] ?? 'sm:grid-cols-7'}`}>
                        {sections.map((item) => {
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
