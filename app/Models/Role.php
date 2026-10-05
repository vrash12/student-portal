<?php

namespace App\Models;

use App\Enums\Permission as PermissionCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'description'])]
class Role extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'rank' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return list<string>
     */
    public function permissionCodes(): array
    {
        return $this->permissions->pluck('code')->values()->all();
    }

    public function grants(PermissionCode $permission): bool
    {
        return $this->permissions->contains('code', $permission->value);
    }

    /**
     * Accounts of this role belong to one campus: those who teach classes,
     * and dietitians, who assess the candidates of their campus (owner
     * decisions 2026-10-03 and 2026-10-05). Only administrator roles may see
     * every campus.
     */
    public function requiresCampus(): bool
    {
        return $this->grants(PermissionCode::TeachClasses) || $this->grants(PermissionCode::ManageNutrition);
    }

    /**
     * Roles that grant access to the staff area (administrators, instructors).
     *
     * @param  Builder<Role>  $query
     */
    #[Scope]
    protected function staff(Builder $query): void
    {
        $query->whereHas('permissions', function (Builder $permissions): void {
            $permissions->where('code', PermissionCode::AccessStaffArea->value);
        });
    }
}
