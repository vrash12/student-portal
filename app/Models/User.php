<?php

namespace App\Models;

use App\Enums\Permission;
use App\Policies\UserPolicy;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
     * The candidate record, for accounts with the Candidate role.
     *
     * @return HasOne<Candidate, $this>
     */
    public function candidate(): HasOne
    {
        return $this->hasOne(Candidate::class);
    }

    /**
     * Subjects and classes this user teaches.
     *
     * @return HasMany<InstructorAssignment, $this>
     */
    public function teachingAssignments(): HasMany
    {
        return $this->hasMany(InstructorAssignment::class, 'instructor_id');
    }

    /**
     * Whether the account may be assigned to teach (active and eligible).
     */
    public function canTeach(): bool
    {
        return $this->hasPermission(Permission::TeachClasses);
    }

    /**
     * Whether the account's role makes it teaching staff, regardless of
     * whether the account is currently active.
     */
    public function isTeachingStaff(): bool
    {
        $this->loadMissing('role.permissions');

        return $this->role->grants(Permission::TeachClasses);
    }

    /**
     * Whether this user currently teaches at least one subject of the class.
     * Instructor access to class and candidate records is scoped by this.
     */
    public function teachesClass(int $classBatchId): bool
    {
        if (! $this->canTeach()) {
            return false;
        }

        return $this->teachingAssignments()
            ->whereHas('classSubject', fn (Builder $offerings) => $offerings->where('class_batch_id', $classBatchId))
            ->exists();
    }

    /**
     * Accounts whose role makes them teaching staff (active or not).
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function teachingStaff(Builder $query): void
    {
        $query->whereHas('role.permissions', function (Builder $permissions): void {
            $permissions->where('code', Permission::TeachClasses->value);
        });
    }

    /**
     * Active teaching staff who can receive new assignments.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function eligibleToTeach(Builder $query): void
    {
        $query->teachingStaff()->where('is_active', true);
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
     * A user may only assign roles ranked below their own role, which
     * prevents privilege escalation through role assignment.
     */
    public function canAssignRole(Role $role): bool
    {
        $this->loadMissing('role');

        return $this->is_active && $this->role->rank > $role->rank;
    }
}
