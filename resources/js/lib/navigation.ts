import {
    Activity,
    Apple,
    Award,
    Building2,
    BookOpen,
    CalendarCheck,
    CalendarRange,
    ClipboardCheck,
    DatabaseBackup,
    Dumbbell,
    FileChartColumn,
    FilePenLine,
    GraduationCap,
    HeartPulse,
    History,
    Layers,
    LayoutDashboard,
    Medal,
    School,
    SlidersHorizontal,
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
    /** Item is hidden when the user also holds this permission (another item covers the same page). */
    hiddenWith?: PermissionCode;
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
            // The Question Bank is part of Examinations (a tab on its page, owner request 2026-10-03).
            {
                label: 'Examinations',
                href: '/examinations',
                icon: ClipboardCheck,
                permission: Permission.ManageExaminations,
                activeFor: ['/examination-attempts', routes.questionBank.index()],
            },
            {
                label: `My ${terms.classBatch.plural}`,
                href: routes.teaching.classes.index(),
                icon: School,
                permission: Permission.TeachClasses,
                activeFor: [routes.candidates.index()],
            },
            {
                label: 'Grade Corrections',
                href: routes.gradeCorrections.index(),
                icon: FilePenLine,
                permission: Permission.RecordGrades,
                hiddenWith: Permission.ApproveGradeCorrections,
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
            // Accounts limited to a campus never hold campuses.manage (Permission::isInstitutionWide).
            { label: 'Campuses', href: routes.campuses.index(), icon: Building2, permission: Permission.ManageCampuses },
            { label: 'Subjects', href: routes.subjects.index(), icon: BookOpen, permission: Permission.ManageSubjects },
            {
                label: 'Academic Periods',
                href: routes.academicPeriods.index(),
                icon: CalendarRange,
                permission: Permission.ManageAcademicPeriods,
            },
            // Phases of the course; each subject of a class belongs to one.
            { label: 'Training Phases', href: routes.trainingPhases.index(), icon: Layers, permission: Permission.ManageAcademicPeriods },
            {
                // Every grading setting in one place; the class subject weights
                // and period passing grades it links to stay under this item.
                label: 'Grading Setup',
                href: routes.gradingSetup.index(),
                icon: SlidersHorizontal,
                permission: Permission.ConfigureGrading,
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
            { label: 'Grade Corrections', href: routes.gradeCorrections.index(), icon: FilePenLine, permission: Permission.ApproveGradeCorrections },
            { label: 'Military Fitness', href: routes.fitness.index(), icon: Dumbbell, permission: Permission.ViewFitness },
            { label: 'Medical Records', href: routes.medical.records.index(), icon: HeartPulse, permission: Permission.ViewMedical },
            { label: 'Nutrition', href: routes.nutrition.index(), icon: Apple, permission: Permission.ViewNutrition },
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
            { label: 'Backups', href: routes.backups.index(), icon: DatabaseBackup, permission: Permission.ManageBackups },
            {
                label: 'Instructors',
                href: routes.instructors.index(),
                icon: UserRoundCog,
                permission: Permission.ManageInstructorAssignments,
            },
            { label: 'Users', href: routes.users.index(), icon: Users, permission: Permission.ViewUsers },
        ],
    },
];

/** Whether the user sees a navigation item, given their permission check. */
export function isVisibleItem(item: NavigationItem, can: (permission: PermissionCode) => boolean): boolean {
    return can(item.permission) && (item.hiddenWith === undefined || !can(item.hiddenWith));
}

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
