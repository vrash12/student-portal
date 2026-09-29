<?php

namespace App\Models;

use App\Enums\Permission;
use App\Policies\UserPolicy;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Role assignment and activation status are deliberately not mass
 * assignable; they are set explicitly by UserAccountService.
 */
#[Fillable(['name', 'username', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
#[UsePolicy(UserPolicy::class)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /**
     * Defaults mirroring the database, so unsaved and freshly created
     * instances expose the same attributes as rows loaded from the table.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'remember_token' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role_id' => 'integer',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Effective permission check. Deactivated accounts hold no permissions.
     */
    public function hasPermission(Permission $permission): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $this->loadMissing('role.permissions');

        return $this->role->grants($permission);
    }

    /**
     * Effective permission codes, used for Inertia navigation visibility only.
     *
     * @return list<string>
     */
    public function permissionCodes(): array
    {
        if (! $this->is_active) {
            return [];
        }

        $this->loadMissing('role.permissions');

        return $this->role->permissionCodes();
    }

    /**
     * Whether the account's role belongs to the staff area, regardless of
     * whether the account is currently active.
     */
    public function isStaffAccount(): bool
    {
        $this->loadMissing('role.permissions');

        return $this->role->grants(Permission::AccessStaffArea);
    }

    /**
     * A user may only grant a role whose permissions they already hold,
     * which prevents privilege escalation through role assignment.
     */
    public function canGrantRole(Role $role): bool
    {
        $role->loadMissing('permissions');

        return array_diff($role->permissionCodes(), $this->permissionCodes()) === [];
    }
}
