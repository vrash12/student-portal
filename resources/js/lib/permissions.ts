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
} as const;

export type PermissionCode = (typeof Permission)[keyof typeof Permission];

export function usePermissions(): { can: (permission: PermissionCode) => boolean } {
    const { permissions } = usePage().props.auth;

    const can = useCallback((permission: PermissionCode) => permissions.includes(permission), [permissions]);

    return { can };
}
