<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Synchronizes permission rows with App\Enums\Permission and resets the
 * system roles to their default permissions. Idempotent and safe to run in
 * every environment, including production.
 */
class AccessControlSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->syncPermissions();
            $this->syncSystemRoles();
        });
    }

    private function syncPermissions(): void
    {
        $codes = [];

        foreach (PermissionCode::cases() as $permission) {
            Permission::query()->updateOrCreate(
                ['code' => $permission->value],
                [
                    'name' => $permission->label(),
                    'description' => $permission->description(),
                    'group' => $permission->group(),
                ],
            );
            $codes[] = $permission->value;
        }

        // Permissions removed from the enum no longer exist in code.
        Permission::query()->whereNotIn('code', $codes)->delete();
    }

    private function syncSystemRoles(): void
    {
        $permissionIds = Permission::query()->pluck('id', 'code');

        foreach (SystemRole::cases() as $systemRole) {
            $role = Role::query()->firstOrNew(['code' => $systemRole->value]);
            $role->fill([
                'name' => $systemRole->label(),
                'description' => $systemRole->description(),
            ]);
            $role->is_system = true;
            $role->save();

            $role->permissions()->sync(
                collect($systemRole->defaultPermissions())
                    ->map(fn (PermissionCode $permission): int => $permissionIds[$permission->value])
                    ->all(),
            );
        }
    }
}
