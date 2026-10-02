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
    ManageAcademicPeriods: 'academic_periods.manage',
    ManageSubjects: 'subjects.manage',
    ManageClassBatches: 'class_batches.manage',
    ManageInstructorAssignments: 'instructor_assignments.manage',
    ViewAllCandidates: 'candidates.view_all',
    ManageCandidates: 'candidates.manage',
    TeachClasses: 'classes.teach',
    ConfigureGrading: 'grading.configure',
    RecordGrades: 'grades.record',
    ApproveGradeCorrections: 'grade_corrections.approve',
    ViewAcademicMonitoring: 'academic_monitoring.view',
    ManageQuestionBank: 'question_bank.manage',
    ManageExaminations: 'examinations.manage',
    ViewReports: 'reports.view',
    ViewAuditHistory: 'audit_history.view',
    ViewFitness: 'fitness.view',
    ManageFitness: 'fitness.manage',
    ConfigureFitness: 'fitness.configure',
    ViewAccounts: 'accounts.view',
    ManageAccounts: 'accounts.manage',
    ManageConduct: 'conduct.manage',
    ManageAttendance: 'attendance.manage',
    ConfigurePerformance: 'performance.configure',
    ViewPerformance: 'performance.view',
    ViewMedical: 'medical.view',
    ManageMedical: 'medical.manage',
    ConfigureMedical: 'medical.configure',
    ManageBackups: 'backups.manage',
} as const;

export type PermissionCode = (typeof Permission)[keyof typeof Permission];

export function usePermissions(): { can: (permission: PermissionCode) => boolean } {
    const { permissions } = usePage().props.auth;

    const can = useCallback((permission: PermissionCode) => permissions.includes(permission), [permissions]);

    return { can };
}
