import {
    Activity,
    Award,
    BookOpen,
    CalendarCheck,
    CalendarRange,
    ClipboardCheck,
    Dumbbell,
    FileChartColumn,
    GraduationCap,
    History,
    LayoutDashboard,
    Library,
    Medal,
    School,
    ShieldCheck,
    UserRoundCog,
    Users,
    UsersRound,
    WalletCards,
    type LucideIcon,
} from 'lucide-react';
import { Permission, type PermissionCode } from '@/lib/permissions';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';

export interface NavigationItem {
    label: string;
    href: string;
    icon: LucideIcon;
    /** Item is shown only when the user holds this permission. */
    permission: PermissionCode;
    /**
     * Extra URL prefixes that highlight this item when no visible item
     * matches directly (e.g. instructors reach candidate profiles from
     * My Classes, without having the Candidates item).
     */
    activeFor?: string[];
}

export interface NavigationSection {
    label: string | null;
    items: NavigationItem[];
}

/**
 * Staff sidebar (UI_UX_DESIGN.md §11). Only implemented modules are listed;
 * never add links to features that do not exist yet (AGENTS.md §73).
 */
export const staffNavigation: NavigationSection[] = [
    {
        label: null,
        items: [{ label: 'Dashboard', href: routes.dashboard(), icon: LayoutDashboard, permission: Permission.AccessStaffArea }],
    },
    {
        label: 'Teaching',
        items: [
            { label: 'Question Bank', href: routes.questionBank.index(), icon: Library, permission: Permission.ManageQuestionBank },
            { label: 'Examinations', href: '/examinations', icon: ClipboardCheck, permission: Permission.ManageExaminations, activeFor: ['/examination-attempts'] },
            {
                label: `My ${terms.classBatch.plural}`,
                href: routes.teaching.classes.index(),
                icon: School,
                permission: Permission.TeachClasses,
                activeFor: [routes.candidates.index()],
            },
        ],
    },
    {
        label: 'Academics',
        items: [
            { label: 'Reports', href: '/reports', icon: FileChartColumn, permission: Permission.ViewReports },
            {
                label: 'Academic Monitoring',
                href: routes.monitoring.index(),
                icon: Activity,
                permission: Permission.ViewAcademicMonitoring,
            },
            { label: terms.candidate.plural, href: routes.candidates.index(), icon: GraduationCap, permission: Permission.ViewAllCandidates },
            { label: terms.classBatch.plural, href: routes.classes.index(), icon: UsersRound, permission: Permission.ManageClassBatches },
            { label: 'Subjects', href: routes.subjects.index(), icon: BookOpen, permission: Permission.ManageSubjects },
            {
                label: 'Academic Periods',
                href: routes.academicPeriods.index(),
                icon: CalendarRange,
                permission: Permission.ManageAcademicPeriods,
            },
        ],
    },
    {
        label: 'Records',
        items: [
            {
                label: 'Qualification',
                href: routes.qualification.index(),
                icon: Award,
                permission: Permission.ViewPerformance,
                activeFor: [routes.performanceAreas.index()],
            },
            { label: 'Military Fitness', href: routes.fitness.index(), icon: Dumbbell, permission: Permission.ViewFitness },
            { label: 'Merits & Demerits', href: routes.conduct.index(), icon: Medal, permission: Permission.ManageConduct },
            { label: 'Attendance', href: routes.attendance.index(), icon: CalendarCheck, permission: Permission.ManageAttendance },
            {
                label: 'Expenses',
                href: routes.accounts.expenses.index(),
                icon: WalletCards,
                permission: Permission.ManageAccounts,
                activeFor: [routes.accounts.categories.index()],
            },
        ],
    },
    {
        label: 'Administration',
        items: [
            { label: 'Audit History', href: '/audit-history', icon: History, permission: Permission.ViewAuditHistory },
            {
                label: 'Instructors',
                href: routes.instructors.index(),
                icon: UserRoundCog,
                permission: Permission.ManageInstructorAssignments,
            },
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

/**
 * The single item to highlight: a direct match first, otherwise an item that
 * claims the URL through `activeFor`.
 */
export function activeItemHref(currentUrl: string, visibleItems: NavigationItem[]): string | null {
    const direct = visibleItems.find((item) => isActivePath(currentUrl, item.href));
    if (direct !== undefined) {
        return direct.href;
    }

    const claimed = visibleItems.find((item) => item.activeFor?.some((prefix) => isActivePath(currentUrl, prefix)));

    return claimed?.href ?? null;
}
