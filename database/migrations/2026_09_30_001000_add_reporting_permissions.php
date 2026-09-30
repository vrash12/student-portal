<?php

use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            foreach ([PermissionCode::ViewReports, PermissionCode::ViewAuditHistory] as $code) {
                $permission = Permission::updateOrCreate(['code' => $code->value], ['name' => $code->label(), 'description' => $code->description(), 'group' => $code->group()]);
                foreach (SystemRole::cases() as $systemRole) {
                    if (in_array($code, $systemRole->defaultPermissions(), true)) {
                        Role::where('code', $systemRole->value)->first()?->permissions()->syncWithoutDetaching([$permission->id]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        Permission::whereIn('code', ['reports.view', 'audit_history.view'])->delete();
    }
};
