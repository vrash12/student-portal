<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates and updates user accounts and records every change in the audit log.
 *
 * Authorization and input validation happen before these methods are called
 * (see UserPolicy and the user Form Requests).
 */
final class UserAccountService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, username: string, email: ?string, password: string, role_id: int, is_active: bool}  $data
     */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = new User([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);
            $user->role()->associate(Role::query()->findOrFail($data['role_id']));
            $user->is_active = $data['is_active'];
            // The administrator chose this password: the owner replaces it at sign-in.
            $user->password_change_required = true;
            $user->save();

            $this->audit->record(AuditAction::UserCreated, $user, newValues: [
                ...$this->profileSnapshot($user),
                'role' => $user->role->name,
                'is_active' => $user->is_active,
            ]);

            return $user;
        });
    }

    /**
     * @param  array{name: string, username: string, email: ?string, password: ?string, role_id: int, is_active: bool}  $data
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $previousProfile = $this->profileSnapshot($user);
            $wasActive = $user->is_active;

            $user->fill([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'],
            ]);

            // The role is fixed once the account exists (UpdateUserRequest); $data['role_id'] is the current role.
            $user->is_active = $data['is_active'];

            $passwordReset = $data['password'] !== null && $data['password'] !== '';
            if ($passwordReset) {
                $user->password = $data['password'];
                $user->password_change_required = true;
            }

            $user->save();

            $this->audit->recordChanges(AuditAction::UserUpdated, $user, $previousProfile, $this->profileSnapshot($user));

            if ($wasActive !== $user->is_active) {
                $this->audit->record($user->is_active ? AuditAction::UserReactivated : AuditAction::UserDeactivated, $user);
            }

            if ($passwordReset) {
                $this->audit->record(AuditAction::UserPasswordReset, $user);
            }

            // Deactivation and password resets end the account's existing
            // sessions so the change takes effect immediately.
            if ($passwordReset || ! $user->is_active) {
                $this->endSessions($user);
            }

            return $user;
        });
    }

    /**
     * A user changing their own password stays signed in on the current
     * device; every other session of theirs is ended.
     */
    public function changeOwnPassword(User $user, string $password, string $currentSessionId): void
    {
        DB::transaction(function () use ($user, $password, $currentSessionId): void {
            $user->password = $password;
            $user->password_change_required = false;
            $user->save();

            $this->endSessions($user, exceptSessionId: $currentSessionId);

            $this->audit->record(AuditAction::OwnPasswordChanged, $user, actor: $user);
        });
    }

    /**
     * Ends every stored session of the user, optionally keeping one.
     */
    public function endSessions(User $user, ?string $exceptSessionId = null): int
    {
        return DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->when($exceptSessionId !== null, fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();
    }

    /**
     * @return array{name: string, username: string, email: ?string}
     */
    private function profileSnapshot(User $user): array
    {
        return [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
        ];
    }
}
