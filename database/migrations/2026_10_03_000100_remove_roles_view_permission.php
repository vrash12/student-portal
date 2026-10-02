<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CODE = 'roles.view';

    /**
     * The read-only Roles & Permissions page was removed (owner request,
     * 2026-10-03), so the permission that only guarded it goes too. Roles
     * and their permissions are unchanged otherwise: they are still assigned
     * on the Users page and defined in App\Enums\SystemRole. Deleting the
     * permission removes its grants (permission_role cascades);
     * AccessControlSeeder does the same for new databases.
     */
    public function up(): void
    {
        DB::table('permissions')->where('code', self::CODE)->delete();
    }

    /**
     * Restores the permission and gives it back to the Admin role, which held
     * it by default. Values are written out because the enum case is gone.
     */
    public function down(): void
    {
        DB::transaction(function (): void {
            $existing = DB::table('permissions')->where('code', self::CODE)->value('id');
            $permissionId = $existing ?? DB::table('permissions')->insertGetId([
                'code' => self::CODE,
                'name' => 'View roles and permissions',
                'description' => 'View roles and the permissions each role grants.',
                'group' => 'Administration',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $adminRoleId = DB::table('roles')->where('code', 'super_admin')->value('id');
            if ($adminRoleId !== null) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $adminRoleId, 'permission_id' => $permissionId]);
            }
        });
    }
};
