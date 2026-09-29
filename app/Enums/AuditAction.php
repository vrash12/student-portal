<?php

namespace App\Enums;

/**
 * Actions recorded in the audit log.
 */
enum AuditAction: string
{
    case Login = 'auth.login';
    case LoginFailed = 'auth.login_failed';
    case Logout = 'auth.logout';
    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserRoleChanged = 'user.role_changed';
    case UserDeactivated = 'user.deactivated';
    case UserReactivated = 'user.reactivated';
    case UserPasswordReset = 'user.password_reset';
    case OwnPasswordChanged = 'account.password_changed';

    public function label(): string
    {
        return match ($this) {
            self::Login => 'Signed in',
            self::LoginFailed => 'Failed sign-in attempt',
            self::Logout => 'Signed out',
            self::UserCreated => 'Created user account',
            self::UserUpdated => 'Updated user account',
            self::UserRoleChanged => 'Changed user role',
            self::UserDeactivated => 'Deactivated user account',
            self::UserReactivated => 'Reactivated user account',
            self::UserPasswordReset => 'Reset user password',
            self::OwnPasswordChanged => 'Changed own password',
        };
    }
}
