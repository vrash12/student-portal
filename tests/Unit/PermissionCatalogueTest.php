<?php

namespace Tests\Unit;

use App\Enums\Permission;
use App\Enums\SystemRole;
use PHPUnit\Framework\TestCase;

class PermissionCatalogueTest extends TestCase
{
    public function test_frontend_permission_constants_match_the_backend_enum(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/lib/permissions.ts');

        $this->assertSame(1, preg_match('/export const Permission = \{(.*?)\} as const;/s', $source, $block));
        preg_match_all("/(\w+):\s*'([^']+)'/", $block[1], $matches, PREG_SET_ORDER);

        $frontend = [];
        foreach ($matches as [, $name, $value]) {
            $frontend[$name] = $value;
        }

        $backend = [];
        foreach (Permission::cases() as $permission) {
            $backend[$permission->name] = $permission->value;
        }

        $this->assertSame($backend, $frontend, 'resources/js/lib/permissions.ts must mirror App\Enums\Permission.');
    }

    public function test_every_permission_is_described(): void
    {
        foreach (Permission::cases() as $permission) {
            $this->assertNotSame('', $permission->label());
            $this->assertNotSame('', $permission->description());
            $this->assertNotSame('', $permission->group());
        }
    }

    public function test_super_administrator_holds_every_staff_permission(): void
    {
        $staffPermissions = array_values(array_filter(
            Permission::cases(),
            fn (Permission $permission): bool => $permission !== Permission::AccessExamPortal,
        ));

        $this->assertEqualsCanonicalizing(
            $this->values($staffPermissions),
            $this->values(SystemRole::SuperAdministrator->defaultPermissions()),
        );
    }

    public function test_candidates_only_receive_examination_portal_access(): void
    {
        $this->assertSame([Permission::AccessExamPortal], SystemRole::Candidate->defaultPermissions());
    }

    public function test_no_staff_role_can_open_the_examination_portal(): void
    {
        foreach ([SystemRole::SuperAdministrator, SystemRole::AcademicAdministrator, SystemRole::Instructor] as $role) {
            $this->assertNotContains(Permission::AccessExamPortal, $role->defaultPermissions(), $role->label());
            $this->assertContains(Permission::AccessStaffArea, $role->defaultPermissions(), $role->label());
        }
    }

    /**
     * @param  list<Permission>  $permissions
     * @return list<string>
     */
    private function values(array $permissions): array
    {
        return array_map(fn (Permission $permission): string => $permission->value, $permissions);
    }
}
