<?php

namespace App\Enums;

use App\Models\User;

/**
 * Who must turn on two-step sign-in before using the staff area
 * (config two_factor.required_for). Staff who are not required may still
 * turn it on; candidates never use it.
 */
enum TwoFactorRequirement: string
{
    case Administrators = 'administrators';
    case Staff = 'staff';
    case None = 'none';

    public static function configured(): self
    {
        return self::tryFrom((string) config('two_factor.required_for')) ?? self::Administrators;
    }

    public function appliesTo(User $user): bool
    {
        if (! $user->isStaffAccount()) {
            return false;
        }

        return match ($this) {
            self::Administrators => $user->hasPermission(Permission::ManageUsers),
            self::Staff => true,
            self::None => false,
        };
    }
}
