import { Link, usePage } from '@inertiajs/react';
import { ChevronDown, KeyRound, LogOut, Menu, X } from 'lucide-react';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { BrandMark } from '@/components/brand-mark';
import { PageHeaderSlot } from '@/components/ui/page-header-slot';
import { Toaster } from '@/components/ui/toaster';
import { cn } from '@/lib/cn';
import { activeItemHref, isVisibleItem, staffNavigation } from '@/lib/navigation';
import { usePermissions } from '@/lib/permissions';
import { routes } from '@/lib/routes';
import { useFocusMainOnNavigate } from '@/lib/use-focus-main-on-navigate';

const DESKTOP_QUERY = '(min-width: 1024px)';

/** Remembers on this device whether the desktop sidebar shows icons only. */
const COLLAPSED_KEY = 'staff-sidebar-collapsed';

function readCollapsed(): boolean {
    try {
        return window.localStorage.getItem(COLLAPSED_KEY) === '1';
    } catch {
        return false;
    }
}

function storeCollapsed(collapsed: boolean): void {
    try {
        window.localStorage.setItem(COLLAPSED_KEY, collapsed ? '1' : '0');
    } catch {
        // Storage may be blocked (private windows); the sidebar still toggles.
    }
}

/**
 * Administrative and instructor shell (UI_UX_DESIGN.md §10–13): persistent
 * sidebar on desktop, drawer navigation on tablets and narrow screens. The
 * desktop sidebar folds to icons only, and back, when the logo is selected.
 */
export default function StaffLayout({ children }: { children: ReactNode }) {
    const { url } = usePage();
    useFocusMainOnNavigate();
    const [navigationOpen, setNavigationOpen] = useState(false);
    const [headerSlot, setHeaderSlot] = useState<HTMLDivElement | null>(null);
    const [collapsed, setCollapsed] = useState(readCollapsed);

    const toggleCollapsed = () => {
        setCollapsed((current) => {
            storeCollapsed(!current);

            return !current;
        });
    };

    useEffect(() => {
        setNavigationOpen(false);
    }, [url]);

    return (
        <div className="min-h-dvh">
            <a
                href="#main-content"
                className="sr-only rounded-md bg-surface px-4 py-2 text-sm font-medium text-ink shadow-md focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50"
            >
                Skip to main content
            </a>

            <aside
                className={cn(
                    'fixed inset-y-0 left-0 z-30 hidden border-r border-primary-800 bg-primary-900 transition-[width] duration-200 motion-reduce:transition-none lg:block print:hidden',
                    collapsed ? 'w-20' : 'w-64',
                )}
            >
                <SidebarContent collapsed={collapsed} onToggle={toggleCollapsed} />
            </aside>

            <NavigationDrawer open={navigationOpen} onClose={() => setNavigationOpen(false)} />

            <div className={cn('transition-[padding] duration-200 motion-reduce:transition-none print:pl-0', collapsed ? 'lg:pl-20' : 'lg:pl-64')}>
                <header className="sticky top-0 z-20 flex h-16 items-center gap-3 border-b-2 border-accent-300 bg-surface px-4 sm:px-6 lg:px-8 print:hidden">
                    <button
                        type="button"
                        onClick={() => setNavigationOpen(true)}
                        className="-ml-2 flex size-11 items-center justify-center rounded-md text-ink-muted hover:bg-neutral-bg hover:text-ink lg:hidden"
                        aria-label="Open navigation menu"
                    >
                        <Menu className="size-5" aria-hidden="true" />
                    </button>
                    <OrganizationName />
                    <UserMenu />
                </header>

                <main id="main-content" tabIndex={-1}>
                    {/* The page title banner, edge to edge (PageHeader renders into it). */}
                    <div ref={setHeaderSlot} />
                    <div className="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                        <div className="mx-auto w-full max-w-7xl">
                            <PageHeaderSlot.Provider value={headerSlot}>{children}</PageHeaderSlot.Provider>
                        </div>
                    </div>
                </main>
            </div>

            <Toaster />
        </div>
    );
}

function OrganizationName() {
    const { app } = usePage().props;

    return (
        <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-medium text-ink-muted">{app.organizationName}</p>
        </div>
    );
}

interface SidebarContentProps {
    inDrawer?: boolean;
    /** Desktop only: icons without labels. */
    collapsed?: boolean;
    /** Desktop only: the logo folds and unfolds the sidebar. */
    onToggle?: () => void;
}

function SidebarContent({ inDrawer = false, collapsed = false, onToggle }: SidebarContentProps) {
    const { url } = usePage();
    const { can } = usePermissions();
    const navId = useId();
    const visibleItems = staffNavigation.flatMap((section) => section.items).filter((item) => isVisibleItem(item, can));
    const activeHref = activeItemHref(url, visibleItems);
    const systemTitle = usePage().props.app.login.cardTitle;
    const toggleLabel = collapsed ? 'Expand navigation' : 'Collapse navigation to icons';

    return (
        <div className="brand-dark flex h-full flex-col bg-primary-900 text-white">
            {/* The logo alone, centred; in the drawer, space is kept for the close button on both sides. */}
            <div className={cn('flex shrink-0 flex-col items-center justify-center gap-2 border-b border-white/15 py-3', inDrawer ? 'px-14' : 'px-3')}>
                {onToggle === undefined ? (
                    <BrandMark round className="size-12" />
                ) : (
                    <button
                        type="button"
                        onClick={onToggle}
                        aria-label={toggleLabel}
                        aria-expanded={!collapsed}
                        aria-controls={navId}
                        title={toggleLabel}
                        className="flex items-center justify-center rounded-full p-0.5 transition-transform hover:scale-105 hover:ring-2 hover:ring-accent-300/70 motion-reduce:transition-none"
                    >
                        <BrandMark round className={collapsed ? 'size-11' : 'size-12'} />
                    </button>
                )}
                {!collapsed && <p className="text-center text-xs leading-snug font-semibold text-accent-200">{systemTitle}</p>}
            </div>

            <nav id={navId} aria-label="Main" className={cn('sidebar-scroll flex-1 overflow-y-auto py-4', collapsed ? 'px-0.5' : 'px-1.5')}>
                {staffNavigation.map((section, sectionIndex) => {
                    const items = section.items.filter((item) => isVisibleItem(item, can));
                    if (items.length === 0) {
                        return null;
                    }

                    return (
                        <div key={section.label ?? `section-${sectionIndex}`} className="mb-6 last:mb-0">
                            {section.label &&
                                (collapsed ? (
                                    // Folded: a thin rule marks the section; its name stays available to screen readers.
                                    <p className="mx-2 mb-3 border-t border-white/15">
                                        <span className="sr-only">{section.label}</span>
                                    </p>
                                ) : (
                                    <p className="mb-2 px-3 text-xs font-semibold uppercase tracking-wider text-primary-200">{section.label}</p>
                                ))}
                            <ul className="space-y-1">
                                {items.map((item) => {
                                    const active = item.href === activeHref;

                                    return (
                                        <li key={item.href}>
                                            <Link
                                                href={item.href}
                                                aria-current={active ? 'page' : undefined}
                                                title={collapsed ? item.label : undefined}
                                                className={cn(
                                                    'flex min-h-11 items-center rounded-lg border-l-4 text-sm font-medium transition-colors pointer-coarse:h-11',
                                                    collapsed ? 'justify-center px-0' : 'gap-3 px-3',
                                                    active
                                                        ? 'border-accent-300 bg-white/10 text-accent-100'
                                                        : 'border-transparent text-primary-100 hover:bg-white/10 hover:text-white',
                                                )}
                                            >
                                                <item.icon
                                                    className={cn('shrink-0', collapsed ? 'size-5' : 'size-4.5', active ? 'text-accent-300' : 'text-primary-200')}
                                                    aria-hidden="true"
                                                />
                                                <span className={collapsed ? 'sr-only' : undefined}>{item.label}</span>
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    );
                })}
            </nav>
        </div>
    );
}

function NavigationDrawer({ open, onClose }: { open: boolean; onClose: () => void }) {
    const dialogRef = useRef<HTMLDialogElement>(null);

    useEffect(() => {
        const dialog = dialogRef.current;
        if (dialog === null) {
            return;
        }

        if (open && !dialog.open) {
            dialog.showModal();
        } else if (!open && dialog.open) {
            dialog.close();
        }
    }, [open]);

    // The drawer is not used on desktop widths; close it if the window grows.
    useEffect(() => {
        const media = window.matchMedia(DESKTOP_QUERY);
        const handleChange = (event: MediaQueryListEvent) => {
            if (event.matches) {
                onClose();
            }
        };
        media.addEventListener('change', handleChange);

        return () => media.removeEventListener('change', handleChange);
    }, [onClose]);

    return (
        <dialog
            ref={dialogRef}
            aria-label="Navigation menu"
            onClose={onClose}
            onClick={(event) => {
                // A click on the backdrop targets the dialog element itself.
                if (event.target === event.currentTarget) {
                    onClose();
                }
            }}
            className="m-0 h-dvh max-h-dvh w-72 max-w-[85vw] brand-dark border-r border-primary-800 bg-primary-900 p-0 lg:hidden"
        >
            <div className="relative h-full">
                <button
                    type="button"
                    onClick={onClose}
                    className="absolute right-2 top-2.5 z-10 flex size-11 items-center justify-center rounded-md text-white hover:bg-white/10"
                    aria-label="Close navigation menu"
                >
                    <X className="size-5" aria-hidden="true" />
                </button>
                <SidebarContent inDrawer />
            </div>
        </dialog>
    );
}

function UserMenu() {
    const { url, props } = usePage();
    const user = props.auth.user;
    const [open, setOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);
    const buttonRef = useRef<HTMLButtonElement>(null);
    const menuId = useId();

    useEffect(() => {
        setOpen(false);
    }, [url]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const handlePointerDown = (event: PointerEvent) => {
            if (event.target instanceof Node && !containerRef.current?.contains(event.target)) {
                setOpen(false);
            }
        };
        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
                buttonRef.current?.focus();
            }
        };

        document.addEventListener('pointerdown', handlePointerDown);
        document.addEventListener('keydown', handleKeyDown);

        return () => {
            document.removeEventListener('pointerdown', handlePointerDown);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [open]);

    if (user === null) {
        return null;
    }

    // An account limited to one campus shows it beside the role.
    const roleAndCampus = user.campus ? `${user.role.name} · ${user.campus.name}` : user.role.name;

    return (
        <div ref={containerRef} className="relative">
            <button
                ref={buttonRef}
                type="button"
                onClick={() => setOpen((current) => !current)}
                aria-expanded={open}
                aria-controls={menuId}
                aria-label={`Account menu for ${user.name}`}
                className="flex h-11 items-center gap-3 rounded-md px-2 hover:bg-neutral-bg"
            >
                <span
                    className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-semibold text-primary-800"
                    aria-hidden="true"
                >
                    {initials(user.name)}
                </span>
                <span className="hidden min-w-0 text-left sm:block">
                    <span className="block max-w-48 truncate text-sm font-medium text-ink">{user.name}</span>
                    <span className="block max-w-48 truncate text-xs text-ink-muted">{roleAndCampus}</span>
                </span>
                <ChevronDown className="size-4 text-ink-muted" aria-hidden="true" />
            </button>

            {open && (
                <div
                    id={menuId}
                    className="absolute right-0 mt-2 w-60 overflow-hidden rounded-lg border border-line-box bg-surface py-1 shadow-lg"
                >
                    <div className="border-b border-line px-4 py-3 sm:hidden">
                        <p className="truncate text-sm font-medium text-ink">{user.name}</p>
                        <p className="truncate text-xs text-ink-muted">{roleAndCampus}</p>
                    </div>
                    <Link
                        href={routes.account.password()}
                        className="flex h-11 items-center gap-3 px-4 text-sm text-ink hover:bg-surface-muted"
                    >
                        <KeyRound className="size-4 text-ink-subtle" aria-hidden="true" />
                        Change Password
                    </Link>
                    <Link
                        href={routes.logout()}
                        method="post"
                        as="button"
                        className="flex h-11 w-full items-center gap-3 px-4 text-left text-sm text-ink hover:bg-surface-muted"
                    >
                        <LogOut className="size-4 text-ink-subtle" aria-hidden="true" />
                        Sign Out
                    </Link>
                </div>
            )}
        </div>
    );
}

function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    const first = parts[0]?.[0] ?? '';
    const last = parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? '') : '';

    return (first + last).toUpperCase();
}
