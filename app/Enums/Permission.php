<?php

namespace App\Enums;

/**
 * Catalogue of every permission the application checks.
 *
 * Each case is registered as a Gate ability (see AppServiceProvider), so
 * authorization always references permissions rather than role names.
 * Roles bundle permissions and live in the database; this enum is the
 * source of truth for which permissions exist.
 *
 * Only add a permission together with the code that enforces it, and mirror
 * it in resources/js/lib/permissions.ts.
 */
enum Permission: string
{
    case AccessStaffArea = 'staff_area.access';
    case AccessExamPortal = 'exam_portal.access';
    case ViewUsers = 'users.view';
    case ManageUsers = 'users.manage';
    case ViewRoles = 'roles.view';
    case ManageAcademicPeriods = 'academic_periods.manage';
    case ManageSubjects = 'subjects.manage';
    case ManageClassBatches = 'class_batches.manage';
    case ManageInstructorAssignments = 'instructor_assignments.manage';
    case ViewAllCandidates = 'candidates.view_all';
    case ManageCandidates = 'candidates.manage';
    case TeachClasses = 'classes.teach';
    case ConfigureGrading = 'grading.configure';
    case RecordGrades = 'grades.record';

    public function label(): string
    {
        return match ($this) {
            self::AccessStaffArea => 'Access staff area',
            self::AccessExamPortal => 'Access examination portal',
            self::ViewUsers => 'View user accounts',
            self::ManageUsers => 'Manage user accounts',
            self::ViewRoles => 'View roles and permissions',
            self::ManageAcademicPeriods => 'Manage academic periods',
            self::ManageSubjects => 'Manage subjects',
            self::ManageClassBatches => 'Manage classes',
            self::ManageInstructorAssignments => 'Manage instructor assignments',
            self::ViewAllCandidates => 'View all candidates',
            self::ManageCandidates => 'Manage candidate records',
            self::TeachClasses => 'Teach assigned classes',
            self::ConfigureGrading => 'Configure grading rules',
            self::RecordGrades => 'Record grades for assigned subjects',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AccessStaffArea => 'Sign in to the administrative and instructor area.',
            self::AccessExamPortal => 'Sign in to the candidate examination portal.',
            self::ViewUsers => 'View the list of staff user accounts.',
            self::ManageUsers => 'Create and update staff user accounts, including role assignment.',
            self::ViewRoles => 'View roles and the permissions each role grants.',
            self::ManageAcademicPeriods => 'Create academic periods and choose the active period.',
            self::ManageSubjects => 'Create, edit, activate, and deactivate subjects.',
            self::ManageClassBatches => 'Create and edit classes and the subjects they take.',
            self::ManageInstructorAssignments => 'Assign instructors to the subjects of each class.',
            self::ViewAllCandidates => 'View every candidate record, regardless of class assignment.',
            self::ManageCandidates => 'Create and update candidate records and their sign-in accounts.',
            self::TeachClasses => 'Can be assigned to teach subjects, and view the classes and candidates they teach.',
            self::ConfigureGrading => 'Set the grading categories and weights used for each subject of a class, and the passing and warning grades that decide academic standing in each academic period.',
            self::RecordGrades => 'Create assessments, record and finalize scores, and correct finalized scores for the subjects they are assigned to teach.',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::AccessStaffArea, self::AccessExamPortal => 'Access',
            self::ViewUsers, self::ManageUsers, self::ViewRoles => 'Administration',
            self::ManageAcademicPeriods, self::ManageSubjects, self::ManageClassBatches,
            self::ManageInstructorAssignments => 'Academic Structure',
            self::ViewAllCandidates, self::ManageCandidates => 'Candidates',
            self::TeachClasses => 'Teaching',
            self::ConfigureGrading, self::RecordGrades => 'Grading',
        };
    }
}
