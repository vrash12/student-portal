import { Link } from '@inertiajs/react';
import { ClipboardCheck, Library, type LucideIcon } from 'lucide-react';
import { cn } from '@/lib/cn';
import { examinationRoutes } from '@/lib/examination-routes';
import { Permission, usePermissions, type PermissionCode } from '@/lib/permissions';
import { routes } from '@/lib/routes';

const TABS: ReadonlyArray<{ key: 'examinations' | 'questions'; label: string; href: string; icon: LucideIcon; permission: PermissionCode }> = [
    { key: 'examinations', label: 'Quizzes & Examinations', href: examinationRoutes.index(), icon: ClipboardCheck, permission: Permission.ManageExaminations },
    { key: 'questions', label: 'Question Bank', href: routes.questionBank.index(), icon: Library, permission: Permission.ManageQuestionBank },
];

/**
 * The two parts of the Examinations section (owner request, 2026-10-03: the
 * Question Bank moved from the sidebar into Examinations). Shown above both
 * lists; each part only for users holding its permission, and nothing when
 * only one part is available.
 */
export function ExaminationSectionTabs({ current }: { current: 'examinations' | 'questions' }) {
    const { can } = usePermissions();
    const tabs = TABS.filter((tab) => can(tab.permission));
    if (tabs.length < 2) {
        return null;
    }

    return (
        <nav aria-label="Examinations section" className="mb-6 flex gap-1 rounded-xl border border-line-box bg-surface p-1">
            {tabs.map((tab) => {
                const active = tab.key === current;
                const Icon = tab.icon;

                return (
                    <Link
                        key={tab.key}
                        href={tab.href}
                        aria-current={active ? 'page' : undefined}
                        className={cn(
                            'flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg px-4 text-sm font-semibold transition-colors sm:flex-none',
                            active ? 'bg-primary-600 text-white' : 'text-primary-800 hover:bg-primary-50',
                        )}
                    >
                        <Icon className="size-4" aria-hidden="true" />
                        {tab.label}
                    </Link>
                );
            })}
        </nav>
    );
}
