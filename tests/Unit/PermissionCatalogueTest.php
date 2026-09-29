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

    /**
     * Area entry for candidates, teaching eligibility, and recording grades
     * for assigned subjects are role-specific capabilities; every other
     * permission is administrative.
     */
    public function test_super_administrator_holds_every_administrative_permission(): void
    {
        $roleSpecific = [Permission::AccessExamPortal, Permission::TeachClasses, Permission::RecordGrades, Permission::ManageQuestionBank];
        $administrative = array_values(array_filter(
            Permission::cases(),
            fn (Permission $permission): bool => ! in_array($permission, $roleSpecific, true),
        ));

        $this->assertEqualsCanonicalizing(
            $this->values($administrative),
            $this->values(SystemRole::SuperAdministrator->defaultPermissions()),
        );
    }

    public function test_role_ranks_follow_the_administrative_hierarchy(): void
    {
        $this->assertGreaterThan(SystemRole::AcademicAdministrator->rank(), SystemRole::SuperAdministrator->rank());
        $this->assertGreaterThan(SystemRole::Instructor->rank(), SystemRole::AcademicAdministrator->rank());
        $this->assertGreaterThan(SystemRole::Candidate->rank(), SystemRole::Instructor->rank());
    }

    public function test_only_instructors_are_eligible_to_teach_by_default(): void
    {
        foreach (SystemRole::cases() as $role) {
            $this->assertSame(
                $role === SystemRole::Instructor,
                in_array(Permission::TeachClasses, $role->defaultPermissions(), true),
                $role->label(),
            );
        }
    }

    /**
     * Instructors record grades for their assigned subjects; administrators
     * configure the grading rules but do not encode grades.
     */
    public function test_grading_permissions_are_split_between_instructors_and_administrators(): void
    {
        foreach (SystemRole::cases() as $role) {
            $this->assertSame(
                $role === SystemRole::Instructor,
                in_array(Permission::RecordGrades, $role->defaultPermissions(), true),
                "{$role->label()}: record grades",
            );
            $this->assertSame(
                in_array($role, [SystemRole::SuperAdministrator, SystemRole::AcademicAdministrator], true),
                in_array(Permission::ConfigureGrading, $role->defaultPermissions(), true),
                "{$role->label()}: configure grading",
            );
        }
    }

    /**
     * Question content and correct answers are confidential: by default only
     * instructors, for the subjects they teach, manage the question bank.
     */
    public function test_only_instructors_manage_the_question_bank_by_default(): void
    {
        foreach (SystemRole::cases() as $role) {
            $this->assertSame(
                $role === SystemRole::Instructor,
                in_array(Permission::ManageQuestionBank, $role->defaultPermissions(), true),
                "{$role->label()}: manage question bank",
            );
        }
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
