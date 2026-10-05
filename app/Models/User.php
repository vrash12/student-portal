<?php

namespace App\Models;

use App\Enums\Permission;
use App\Policies\UserPolicy;
use App\Support\CampusScope;
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
 * Role assignment, activation status and campus are deliberately not mass
 * assignable; they are set explicitly by UserAccountService.
 *
 * campus_id (staff accounts): the campus the account is limited to, or null
 * for an account that sees every campus (administrators only; instructors
 * always belong to one campus). Candidate accounts leave it null: a
 * candidate's campus is on the candidate record.
 */
#[Fillable(['name', 'username', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_step'])]
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
        'password_change_required' => false,
        'remember_token' => null,
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
        'two_factor_last_step' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role_id' => 'integer',
            'campus_id' => 'integer',
            'is_active' => 'boolean',
            'password_change_required' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_step' => 'integer',
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
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * The campuses this account may see and work with: its own campus, or
     * every campus when it has none (App\Support\CampusScope).
     */
    public function campusScope(): CampusScope
    {
        return CampusScope::for($this);
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
     * Whether this user currently teaches the given subject of a class.
     * Grade access is scoped by this.
     */
    public function teachesOffering(int $classSubjectId): bool
    {
        if (! $this->canTeach()) {
            return false;
        }

        return $this->teachingAssignments()->where('class_subject_id', $classSubjectId)->exists();
    }

    /**
     * Whether this user currently teaches the subject in at least one class
     * (any academic period). Question bank access is scoped by this.
     */
    public function teachesSubject(int $subjectId): bool
    {
        if (! $this->canTeach()) {
            return false;
        }

        return $this->teachingAssignments()
            ->whereHas('classSubject', fn (Builder $offerings) => $offerings->where('subject_id', $subjectId))
            ->exists();
    }

    /**
     * Ids of the subjects this user currently teaches in at least one class
     * (any academic period), in ascending order. Empty when the user cannot teach.
     *
     * @return list<int>
     */
    public function taughtSubjectIds(): array
    {
        if (! $this->canTeach()) {
            return [];
        }

        return ClassSubject::query()
            ->whereHas('instructorAssignments', fn (Builder $assignments) => $assignments->where('instructor_id', $this->id))
            ->distinct()
            ->orderBy('subject_id')
            ->pluck('subject_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
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
     * Effective permission check. Deactivated accounts hold no permissions,
     * and accounts limited to a campus never hold the institution-wide ones
     * (Permission::isInstitutionWide).
     */
    public function hasPermission(Permission $permission): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($permission->isInstitutionWide() && $this->isCampusLimited()) {
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
        $codes = $this->role->permissionCodes();

        if (! $this->isCampusLimited()) {
            return $codes;
        }

        return array_values(array_filter(
            $codes,
            fn (string $code): bool => ! (Permission::tryFrom($code)?->isInstitutionWide() ?? false),
        ));
    }

    /** Whether the account is limited to one campus (App\Support\CampusScope). */
    public function isCampusLimited(): bool
    {
        return $this->getAttribute('campus_id') !== null;
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

    /** Two-step sign-in is on once its setup was confirmed with a code (TwoFactorService). */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
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
