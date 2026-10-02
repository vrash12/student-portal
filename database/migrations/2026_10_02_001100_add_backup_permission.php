<?php

use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backups and restores (owner request, 2026-10-02): adds backups.manage
     * to existing databases and grants it to the Admin role, which holds it by
     * default (AccessControlSeeder does the same for new databases). Backups
     * themselves are files (config/backups.php), not tables.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $code = PermissionCode::ManageBackups;
            $permission = Permission::query()->updateOrCreate(
                ['code' => $code->value],
                ['name' => $code->label(), 'description' => $code->description(), 'group' => $code->group()],
            );
            foreach (SystemRole::cases() as $systemRole) {
                if (in_array($code, $systemRole->defaultPermissions(), true)) {
                    Role::query()->where('code', $systemRole->value)->first()?->permissions()->syncWithoutDetaching([$permission->id]);
                }
            }
        });
    }

    public function down(): void
    {
        Permission::query()->where('code', PermissionCode::ManageBackups->value)->delete();
    }
};
