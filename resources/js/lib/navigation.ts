import { LayoutDashboard, ShieldCheck, Users, type LucideIcon } from 'lucide-react';
import { Permission, type PermissionCode } from '@/lib/permissions';
import { routes } from '@/lib/routes';

export interface NavigationItem {
    label: string;
    href: string;
    icon: LucideIcon;
    /** Item is shown only when the user holds this permission. */
    permission: PermissionCode;
}

export interface NavigationSection {
    label: string | null;
    items: NavigationItem[];
}

/**
 * Staff sidebar. Only implemented modules are listed; never add links to
 * features that do not exist yet (AGENTS.md §73).
 */
export const staffNavigation: NavigationSection[] = [
    {
        label: null,
        items: [{ label: 'Dashboard', href: routes.dashboard(), icon: LayoutDashboard, permission: Permission.AccessStaffArea }],
    },
    {
        label: 'Administration',
        items: [
            { label: 'Users', href: routes.users.index(), icon: Users, permission: Permission.ViewUsers },
            { label: 'Roles & Permissions', href: routes.roles.index(), icon: ShieldCheck, permission: Permission.ViewRoles },
        ],
    },
];

/** Whether `currentUrl` is the item's page or one of its sub-pages. */
export function isActivePath(currentUrl: string, href: string): boolean {
    const path = currentUrl.split('?')[0] ?? currentUrl;

    return path === href || path.startsWith(`${href}/`);
}
