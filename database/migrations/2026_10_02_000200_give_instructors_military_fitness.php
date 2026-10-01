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
     * Military fitness for instructors (owner request, 2026-10-02): the new
     * "configure fitness standards" permission (events and their points,
     * previously part of fitness.manage), and instructors receive viewing and
     * managing (for the classes they teach). Configuring is for
     * administrators (2026_10_02_000300).
     */
    private const CODES = [
        PermissionCode::ViewFitness,
        PermissionCode::ManageFitness,
        PermissionCode::ConfigureFitness,
    ];

    /**
     * Adds or updates the permissions and grants them to the system roles
     * that hold them by default (AccessControlSeeder does the same for new
     * databases).
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
        DB::transaction(function (): void {
            $instructor = Role::query()->where('code', SystemRole::Instructor->value)->first();
            $fitness = Permission::query()->whereIn('code', [PermissionCode::ViewFitness->value, PermissionCode::ManageFitness->value])->pluck('id');
            $instructor?->permissions()->detach($fitness->all());
            Permission::query()->where('code', PermissionCode::ConfigureFitness->value)->delete();
        });
    }
};
