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
     * Owner decision 2026-10-02: the fitness events and their points apply to
     * every class, so only administrators set them. Instructors keep viewing,
     * creating and recording the fitness tests of the classes they teach.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $configure = Permission::query()->where('code', PermissionCode::ConfigureFitness->value)->value('id');
            if ($configure !== null) {
                Role::query()->where('code', SystemRole::Instructor->value)->first()?->permissions()->detach([$configure]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $configure = Permission::query()->where('code', PermissionCode::ConfigureFitness->value)->value('id');
            if ($configure !== null) {
                Role::query()->where('code', SystemRole::Instructor->value)->first()?->permissions()->syncWithoutDetaching([$configure]);
            }
        });
    }
};
