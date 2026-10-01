<?php

namespace App\Services\Attendance;

use App\Enums\Permission;
use App\Models\User;
use App\Support\ClassScope;

/**
 * Which classes a user may keep attendance for (ClassScope): users who can
 * view all candidates every class, attendance managers who teach only the
 * classes they teach, anyone else nothing. Every attendance page, the
 * policy and the candidate profile start from this rule, so knowing a
 * session's URL never grants access to it.
 */
final class AttendanceScope
{
    public static function for(User $user): ClassScope
    {
        return ClassScope::for($user, Permission::ManageAttendance);
    }
}
