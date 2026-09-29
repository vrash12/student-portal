<?php

namespace App\Enums;

/**
 * Roles created by the system seeder.
 *
 * The permission lists below are the defaults applied by AccessControlSeeder.
 * Application code must check permissions, not these role codes.
 */
enum SystemRole: string
{
    case SuperAdministrator = 'super_admin';
    case AcademicAdministrator = 'academic_admin';
    case Instructor = 'instructor';
    case Candidate = 'candidate';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdministrator => 'Super Administrator',
            self::AcademicAdministrator => 'Academic Administrator',
            self::Instructor => 'Instructor',
            self::Candidate => 'Candidate',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdministrator => 'Full system administration, including roles and administrator accounts.',
            self::AcademicAdministrator => 'Manages academic operations and staff accounts below administrator level.',
            self::Instructor => 'Teaches assigned subjects and classes.',
            self::Candidate => 'Takes quizzes and examinations through the examination portal.',
        };
    }

    /**
     * @return list<Permission>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::SuperAdministrator => [
                Permission::AccessStaffArea,
                Permission::ViewUsers,
                Permission::ManageUsers,
                Permission::ViewRoles,
            ],
            self::AcademicAdministrator => [
                Permission::AccessStaffArea,
                Permission::ViewUsers,
                Permission::ManageUsers,
            ],
            self::Instructor => [
                Permission::AccessStaffArea,
            ],
            self::Candidate => [
                Permission::AccessExamPortal,
            ],
        };
    }
}
