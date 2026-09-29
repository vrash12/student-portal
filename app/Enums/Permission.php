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
 * Only add a permission together with the code that enforces it.
 */
enum Permission: string
{
    case AccessStaffArea = 'staff_area.access';
    case AccessExamPortal = 'exam_portal.access';
    case ViewUsers = 'users.view';
    case ManageUsers = 'users.manage';
    case ViewRoles = 'roles.view';

    public function label(): string
    {
        return match ($this) {
            self::AccessStaffArea => 'Access staff area',
            self::AccessExamPortal => 'Access examination portal',
            self::ViewUsers => 'View user accounts',
            self::ManageUsers => 'Manage user accounts',
            self::ViewRoles => 'View roles and permissions',
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
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::AccessStaffArea, self::AccessExamPortal => 'Access',
            self::ViewUsers, self::ManageUsers, self::ViewRoles => 'Administration',
        };
    }
}
