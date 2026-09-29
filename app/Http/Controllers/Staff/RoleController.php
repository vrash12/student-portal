<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Role;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only overview of roles and the permissions each grants.
 */
class RoleController extends Controller
{
    public function index(): Response
    {
        $roles = Role::query()
            ->with('permissions')
            ->withCount('users')
            ->orderBy('id')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'code' => $role->code,
                'name' => $role->name,
                'description' => $role->description,
                'isSystem' => $role->is_system,
                'userCount' => (int) $role->users_count,
                'permissions' => $role->permissionCodes(),
            ])
            ->all();

        $permissionGroups = collect(Permission::cases())
            ->groupBy(fn (Permission $permission): string => $permission->group())
            ->map(fn ($permissions, string $group): array => [
                'group' => $group,
                'permissions' => $permissions->map(fn (Permission $permission): array => [
                    'code' => $permission->value,
                    'label' => $permission->label(),
                    'description' => $permission->description(),
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return Inertia::render('staff/roles/index', [
            'roles' => $roles,
            'permissionGroups' => $permissionGroups,
        ]);
    }
}
