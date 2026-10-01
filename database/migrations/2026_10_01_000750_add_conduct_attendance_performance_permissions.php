<?php

use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Merits/demerits, attendance, and performance areas/qualification (owner request, 2026-10-01). */
    private const CODES = [
        PermissionCode::ManageConduct,
        PermissionCode::ManageAttendance,
        PermissionCode::ConfigurePerformance,
        PermissionCode::ViewPerformance,
    ];

    /**
     * Adds the permissions to existing databases and grants them to the
     * system roles that hold them by default (AccessControlSeeder does the
     * same for new databases).
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (self::CODES as $code) {
                $permission = Permission::query()->updateOrCreate(
                    ['code' => $code->value],
                    ['name' => $code->label(), 'description' => $code->description(), 'group' => $code->group()],
                );
                foreach (SystemRole::cases() as $systemRole) {
                    if (in_array($code, $systemRole->defaultPermissions(), true)) {
                        Role::query()->where('code', $systemRole->value)->first()?->permissions()->syncWithoutDetaching([$permission->id]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        Permission::query()->whereIn('code', array_map(fn (PermissionCode $code): string => $code->value, self::CODES))->delete();
    }
};
