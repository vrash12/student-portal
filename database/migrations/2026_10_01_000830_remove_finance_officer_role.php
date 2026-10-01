<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CODE = 'finance_officer';

    /**
     * The Finance Officer role is dropped (owner decision, 2026-10-01): the
     * administrator charges candidates. A role without accounts is deleted.
     * Accounts that held it may have recorded charges, so they are kept for
     * history but deactivated, and the role loses every permission (it then
     * no longer counts as a staff role and cannot be assigned).
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $roleId = DB::table('roles')->where('code', self::CODE)->value('id');
            if ($roleId === null) {
                return;
            }

            DB::table('permission_role')->where('role_id', $roleId)->delete();

            if (! DB::table('users')->where('role_id', $roleId)->exists()) {
                DB::table('roles')->where('id', $roleId)->delete();

                return;
            }

            DB::table('users')->where('role_id', $roleId)->update(['is_active' => false, 'updated_at' => now()]);
            DB::table('roles')->where('id', $roleId)->update([
                'name' => 'Finance Officer (removed)',
                'description' => 'No longer used. Kept only for the history of former accounts.',
                'is_system' => false,
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Not reversible: the role and its permissions are not restored.
     */
    public function down(): void {}
};
