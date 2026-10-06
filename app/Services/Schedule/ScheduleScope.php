<?php

namespace App\Services\Schedule;

use App\Enums\Permission;
use App\Models\User;
use App\Support\ClassScope;

/**
 * Which classes' schedules a user may see and change (owner request,
 * 2026-10-06), through ClassScope: users who can view all candidates every
 * class of their campus, teaching staff the classes they teach. Seeing
 * needs schedule.view, changing schedule.manage; knowing an entry's URL
 * never grants access to it.
 */
final class ScheduleScope
{
    /** Classes whose schedule the user sees (none without schedule.view). */
    public static function viewing(User $user): ClassScope
    {
        return ClassScope::for($user, Permission::ViewSchedule);
    }

    /** Classes whose schedule the user changes (checked together with schedule.manage). */
    public static function managing(User $user): ClassScope
    {
        return ClassScope::for($user, Permission::ManageSchedule);
    }

    public static function canView(User $user, int $classBatchId): bool
    {
        return $user->hasPermission(Permission::ViewSchedule) && self::viewing($user)->allows($classBatchId);
    }

    public static function canManage(User $user, int $classBatchId): bool
    {
        return $user->hasPermission(Permission::ManageSchedule) && self::managing($user)->allows($classBatchId);
    }
}
