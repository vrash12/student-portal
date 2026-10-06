<?php

namespace App\Enums;

/**
 * Roles created by the system seeder.
 *
 * The permission lists and ranks below are the defaults applied by
 * AccessControlSeeder. Application code must check permissions, not these
 * role codes.
 */
enum SystemRole: string
{
    case SuperAdministrator = 'super_admin';
    case AcademicAdministrator = 'academic_admin';
    case Instructor = 'instructor';
    case Dietitian = 'dietitian';
    case Candidate = 'candidate';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdministrator => 'Admin',
            self::AcademicAdministrator => 'Academic Administrator',
            self::Instructor => 'Instructor',
            self::Dietitian => 'Dietitian',
            self::Candidate => 'Candidate',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdministrator => 'Full system administration, including roles and administrator accounts.',
            self::AcademicAdministrator => 'Manages academic records and staff accounts below administrator level.',
            self::Instructor => 'Teaches assigned subjects and classes.',
            self::Dietitian => 'Assesses and monitors the nutrition of the candidates of their campus.',
            self::Candidate => 'Takes quizzes and examinations through the examination portal.',
        };
    }

    /**
     * Users may only assign roles, and manage accounts holding roles, ranked
     * below their own.
     */
    public function rank(): int
    {
        return match ($this) {
            self::SuperAdministrator => 100,
            self::AcademicAdministrator => 80,
            self::Instructor => 40,
            self::Dietitian => 40,
            self::Candidate => 10,
        };
    }

    /**
     * @return list<Permission>
     */
    public function defaultPermissions(): array
    {
        $academicAdministration = [
            Permission::AccessStaffArea,
            Permission::ViewUsers,
            Permission::ManageUsers,
            Permission::ManageAcademicPeriods,
            Permission::ManageSubjects,
            Permission::ManageClassBatches,
            Permission::ManageInstructorAssignments,
            Permission::ViewAllCandidates,
            Permission::ManageCandidates,
            Permission::ConfigureGrading,
            Permission::ApproveGradeCorrections,
            Permission::ViewAcademicMonitoring,
            Permission::ViewReports,
            Permission::ViewAuditHistory,
            Permission::ViewFitness,
            Permission::ManageFitness,
            Permission::ConfigureFitness,
            Permission::ManageConduct,
            Permission::ManageAttendance,
            Permission::ConfigurePerformance,
            Permission::ViewPerformance,
            // Candidate medical records (owner request, 2026-10-02): administrators only.
            Permission::ViewMedical,
            Permission::ManageMedical,
            Permission::ConfigureMedical,
            // Nutrition (owner request, 2026-10-05): administrators see every record and set the
            // standards; the assessments themselves are recorded by dietitians.
            Permission::ViewNutrition,
            Permission::ConfigureNutrition,
            // Notices to candidates (owner request, 2026-10-06).
            Permission::ManageAnnouncements,
            // Class timetables (owner request, 2026-10-06).
            Permission::ViewSchedule,
            Permission::ManageSchedule,
        ];

        return match ($this) {
            // Expenses: the Admin charges candidates (there is
            // no finance role; owner decision 2026-10-01).
            // Backups and restores: the Admin only (owner request, 2026-10-02).
            // Campuses: the Admin only, and only accounts not limited to a campus (owner decision 2026-10-03).
            self::SuperAdministrator => [...$academicAdministration, Permission::ViewAccounts, Permission::ManageAccounts, Permission::ManageBackups, Permission::ManageCampuses],
            self::AcademicAdministrator => [...$academicAdministration, Permission::ViewAccounts],
            self::Instructor => [
                Permission::AccessStaffArea,
                Permission::TeachClasses,
                Permission::RecordGrades,
                Permission::ViewAcademicMonitoring,
                Permission::ManageQuestionBank,
                Permission::ManageExaminations,
                Permission::ViewReports,
                Permission::ManageConduct,
                Permission::ManageAttendance,
                // Military fitness for the classes they teach (owner request 2026-10-02). The events
                // and their points (ConfigureFitness) apply to every class: administrators only.
                Permission::ViewFitness,
                Permission::ManageFitness,
                // Notices to the classes they teach (owner request, 2026-10-06).
                Permission::ManageAnnouncements,
                // Their own week and the schedules of the classes they teach; administrators set the schedules.
                Permission::ViewSchedule,
            ],
            // Nutrition monitoring of the candidates of their campus (owner request, 2026-10-05).
            self::Dietitian => [
                Permission::AccessStaffArea,
                Permission::ViewNutrition,
                Permission::ManageNutrition,
            ],
            self::Candidate => [
                Permission::AccessExamPortal,
            ],
        };
    }
}
