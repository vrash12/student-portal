import { Link, usePage } from '@inertiajs/react';
import { ChevronDown, KeyRound, LogOut, Menu, X } from 'lucide-react';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { BrandMark } from '@/components/brand-mark';
import { Toaster } from '@/components/ui/toaster';
import { cn } from '@/lib/cn';
import { isActivePath, staffNavigation } from '@/lib/navigation';
import { usePermissions } from '@/lib/permissions';
import { routes } from '@/lib/routes';

const DESKTOP_QUERY = '(min-width: 1024px)';

/**
 * Administrative and instructor shell (UI_UX_DESIGN.md §10–13): persistent
 * sidebar on desktop, drawer navigation on tablets and narrow screens.
 */
export default function StaffLayout({ children }: { children: ReactNode }) {
    const { url } = usePage();
    const [navigationOpen, setNavigationOpen] = useState(false);

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

            <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 border-r border-line bg-surface lg:block">
                <SidebarContent />
            </aside>

            <NavigationDrawer open={navigationOpen} onClose={() => setNavigationOpen(false)} />

            <div className="lg:pl-64">
                <header className="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-line bg-surface px-4 sm:px-6 lg:px-8">
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

                <main id="main-content" tabIndex={-1} className="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                    <div className="mx-auto w-full max-w-7xl">{children}</div>
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

function SidebarContent({ inDrawer = false }: { inDrawer?: boolean }) {
    const { url, props } = usePage();
    const { can } = usePermissions();

    return (
        <div className="flex h-full flex-col">
            {/* In the drawer, space is reserved for the close button. */}
            <div className={cn('flex h-16 shrink-0 items-center gap-3 border-b border-line pl-5', inDrawer ? 'pr-14' : 'pr-5')}>
                <BrandMark />
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-ink">{props.app.shortName}</p>
                    <p className="truncate text-xs text-ink-muted">{props.app.name}</p>
                </div>
            </div>

            <nav aria-label="Main" className="flex-1 overflow-y-auto px-3 py-4">
                {staffNavigation.map((section, sectionIndex) => {
                    const items = section.items.filter((item) => can(item.permission));
                    if (items.length === 0) {
                        return null;
                    }

                    return (
                        <div key={section.label ?? `section-${sectionIndex}`} className="mb-6 last:mb-0">
                            {section.label && (
                                <p className="mb-2 px-3 text-xs font-semibold uppercase tracking-wider text-ink-subtle">
                                    {section.label}
                                </p>
                            )}
                            <ul className="space-y-1">
                                {items.map((item) => {
                                    const active = isActivePath(url, item.href);

                                    return (
                                        <li key={item.href}>
                                            <Link
                                                href={item.href}
                                                aria-current={active ? 'page' : undefined}
                                                className={cn(
                                                    'flex h-10 items-center gap-3 rounded-md px-3 text-sm font-medium transition-colors pointer-coarse:h-11',
                                                    active
                                                        ? 'bg-primary-50 text-primary-800'
                                                        : 'text-ink-muted hover:bg-surface-muted hover:text-ink',
                                                )}
                                            >
                                                <item.icon
                                                    className={cn('size-4.5 shrink-0', active ? 'text-primary-600' : 'text-ink-subtle')}
                                                    aria-hidden="true"
                                                />
                                                {item.label}
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
            className="m-0 h-dvh max-h-dvh w-72 max-w-[85vw] border-r border-line bg-surface p-0 lg:hidden"
        >
            <div className="relative h-full">
                <button
                    type="button"
                    onClick={onClose}
                    className="absolute right-2 top-2.5 z-10 flex size-11 items-center justify-center rounded-md text-ink-muted hover:bg-neutral-bg hover:text-ink"
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
                    <span className="block max-w-48 truncate text-xs text-ink-muted">{user.role.name}</span>
                </span>
                <ChevronDown className="size-4 text-ink-muted" aria-hidden="true" />
            </button>

            {open && (
                <div
                    id={menuId}
                    className="absolute right-0 mt-2 w-60 overflow-hidden rounded-lg border border-line bg-surface py-1 shadow-lg"
                >
                    <div className="border-b border-line px-4 py-3 sm:hidden">
                        <p className="truncate text-sm font-medium text-ink">{user.name}</p>
                        <p className="truncate text-xs text-ink-muted">{user.role.name}</p>
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
