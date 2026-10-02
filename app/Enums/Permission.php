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
    case ApproveGradeCorrections = 'grade_corrections.approve';
    case ViewAcademicMonitoring = 'academic_monitoring.view';
    case ManageQuestionBank = 'question_bank.manage';
    case ManageExaminations = 'examinations.manage';
    case ViewReports = 'reports.view';
    case ViewAuditHistory = 'audit_history.view';
    case ViewFitness = 'fitness.view';
    case ManageFitness = 'fitness.manage';
    case ConfigureFitness = 'fitness.configure';
    case ViewAccounts = 'accounts.view';
    case ManageAccounts = 'accounts.manage';
    case ManageConduct = 'conduct.manage';
    case ManageAttendance = 'attendance.manage';
    case ConfigurePerformance = 'performance.configure';
    case ViewPerformance = 'performance.view';
    case ViewMedical = 'medical.view';
    case ManageMedical = 'medical.manage';
    case ConfigureMedical = 'medical.configure';

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
            self::ApproveGradeCorrections => 'Approve grade corrections',
            self::ViewAcademicMonitoring => 'View academic monitoring',
            self::ManageQuestionBank => 'Manage the question bank',
            self::ManageExaminations => 'Manage quizzes and examinations',
            self::ViewReports => 'View academic and examination reports',
            self::ViewAuditHistory => 'View audit history',
            self::ViewFitness => 'View military fitness records',
            self::ManageFitness => 'Manage military fitness tests',
            self::ConfigureFitness => 'Configure military fitness standards',
            self::ViewAccounts => 'View statements of account',
            self::ManageAccounts => 'Record statement of account entries',
            self::ManageConduct => 'Record merits and demerits',
            self::ManageAttendance => 'Record attendance',
            self::ConfigurePerformance => 'Configure performance areas and qualification',
            self::ViewPerformance => 'View qualification and class ranking',
            self::ViewMedical => 'View candidate medical records',
            self::ManageMedical => 'Record candidate medical records',
            self::ConfigureMedical => 'Configure medical record fields',
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
            self::RecordGrades => 'Create assessments, record and finalize scores, and request corrections of finalized scores (with an incident report) for the subjects they are assigned to teach.',
            self::ApproveGradeCorrections => 'Review grade correction requests and their incident reports, and approve them (the finalized score is then changed) or reject them with a reason. Nobody can approve their own request.',
            self::ViewAcademicMonitoring => 'See academic standings and the candidates who need attention: every candidate for users who can view all candidates, otherwise only the classes and subjects the user teaches. Candidate profiles follow their own permissions.',
            self::ManageQuestionBank => 'Create, edit, preview, activate, and deactivate questions, including their correct answers, for the subjects they are assigned to teach.',
            self::ManageExaminations => 'Create quizzes and examinations for the class subjects they are assigned to teach, choose their questions from the question bank, configure them, and publish them.',
            self::ViewReports => 'View and print reports for all candidates when permitted, otherwise only assigned classes and subjects.',
            self::ViewAuditHistory => 'Read institutional audit records and academic change metadata. Does not allow modifying history.',
            self::ViewFitness => 'View fitness tests, their results, and the fitness history on candidate profiles: for every class when the user can view all candidates, otherwise for the classes the user teaches.',
            self::ManageFitness => 'Create fitness tests and record candidates\' results: for every class when the user can view all candidates, otherwise for the classes the user teaches.',
            self::ConfigureFitness => 'Set the fitness events (push-ups, sit-ups, runs and others), their points tables and passing points. Changes apply to fitness tests created afterwards.',
            self::ViewAccounts => 'View candidates\' statements of account (charges, credits, and balances) and download them as PDF.',
            self::ManageAccounts => 'Record charges and credits on candidates\' statements of account, void mistaken entries with a reason, and manage the account categories. Records amounts only; no payments are processed.',
            self::ManageConduct => 'Record merits and demerits and void mistaken entries with a reason: for every candidate when the user can view all candidates, otherwise for the classes the user teaches.',
            self::ManageAttendance => 'Create training sessions and record attendance: for every class when the user can view all candidates, otherwise for the classes the user teaches.',
            self::ConfigurePerformance => 'Set the performance areas, their weights, passing grades and must-pass rules, which subjects belong to each area, the conduct rating rule, and the merit and demerit types.',
            self::ViewPerformance => 'See every candidate\'s area results, qualification status, and class rank.',
            self::ViewMedical => 'See every candidate\'s full medical record, its change history and uploaded documents (open and download). Instructors without it see nothing medical unless the medical staff approve their request for a full record.',
            self::ManageMedical => 'Record medical values (every change kept in the history), accept or return the documents candidates upload, and approve (1, 7 or 30 days), reject or withdraw instructors\' requests to see a full record.',
            self::ConfigureMedical => 'Define the medical record fields (name, type, choices), and whether instructors and candidates see each field.',
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
            self::ConfigureGrading, self::RecordGrades, self::ApproveGradeCorrections => 'Grading',
            self::ViewAcademicMonitoring => 'Monitoring',
            self::ViewReports => 'Monitoring',
            self::ViewAuditHistory => 'Administration',
            self::ManageQuestionBank, self::ManageExaminations => 'Assessments',
            self::ViewFitness, self::ManageFitness, self::ConfigureFitness => 'Military Fitness',
            self::ViewAccounts, self::ManageAccounts => 'Accounts',
            self::ManageConduct, self::ManageAttendance => 'Conduct & Attendance',
            self::ConfigurePerformance, self::ViewPerformance => 'Performance',
            self::ViewMedical, self::ManageMedical, self::ConfigureMedical => 'Medical Records',
        };
    }
}
