import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';

/**
 * Mirror of App\Enums\Permission. Kept in sync by
 * tests/Unit/PermissionCatalogueTest.php.
 *
 * Frontend checks only control visibility; the server authorizes every request.
 */
export const Permission = {
    AccessStaffArea: 'staff_area.access',
    AccessExamPortal: 'exam_portal.access',
    ViewUsers: 'users.view',
    ManageUsers: 'users.manage',
    ViewRoles: 'roles.view',
    ManageAcademicPeriods: 'academic_periods.manage',
    ManageSubjects: 'subjects.manage',
    ManageClassBatches: 'class_batches.manage',
    ManageInstructorAssignments: 'instructor_assignments.manage',
    ViewAllCandidates: 'candidates.view_all',
    ManageCandidates: 'candidates.manage',
    TeachClasses: 'classes.teach',
    ConfigureGrading: 'grading.configure',
    RecordGrades: 'grades.record',
    ViewAcademicMonitoring: 'academic_monitoring.view',
    ManageQuestionBank: 'question_bank.manage',
    ManageExaminations: 'examinations.manage',
} as const;

export type PermissionCode = (typeof Permission)[keyof typeof Permission];

export function usePermissions(): { can: (permission: PermissionCode) => boolean } {
    const { permissions } = usePage().props.auth;

    const can = useCallback((permission: PermissionCode) => permissions.includes(permission), [permissions]);

    return { can };
}
