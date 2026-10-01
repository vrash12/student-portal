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
    case FinanceOfficer = 'finance_officer';
    case Instructor = 'instructor';
    case Candidate = 'candidate';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdministrator => 'Super Administrator',
            self::AcademicAdministrator => 'Academic Administrator',
            self::FinanceOfficer => 'Finance Officer',
            self::Instructor => 'Instructor',
            self::Candidate => 'Candidate',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdministrator => 'Full system administration, including roles and administrator accounts.',
            self::AcademicAdministrator => 'Manages academic records and staff accounts below administrator level.',
            self::FinanceOfficer => 'Records charges and credits on the statements of account of candidates. No access to academic records.',
            self::Instructor => 'Teaches assigned subjects and classes.',
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
            self::FinanceOfficer => 50,
            self::Instructor => 40,
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
            Permission::ViewAcademicMonitoring,
            Permission::ViewReports,
            Permission::ViewAuditHistory,
            Permission::ViewFitness,
            Permission::ManageFitness,
        ];

        return match ($this) {
            // Statements of Account: the Super Administrator records entries,
            // the Academic Administrator may only view them.
            self::SuperAdministrator => [...$academicAdministration, Permission::ViewRoles, Permission::ViewAccounts, Permission::ManageAccounts],
            self::AcademicAdministrator => [...$academicAdministration, Permission::ViewAccounts],
            self::FinanceOfficer => [
                Permission::AccessStaffArea,
                Permission::ViewAccounts,
                Permission::ManageAccounts,
            ],
            self::Instructor => [
                Permission::AccessStaffArea,
                Permission::TeachClasses,
                Permission::RecordGrades,
                Permission::ViewAcademicMonitoring,
                Permission::ManageQuestionBank,
                Permission::ManageExaminations,
                Permission::ViewReports,
            ],
            self::Candidate => [
                Permission::AccessExamPortal,
            ],
        };
    }
}
