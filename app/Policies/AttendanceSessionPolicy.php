<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AttendanceSession;
use App\Models\ClassBatch;
use App\Models\User;
use App\Services\Attendance\AttendanceScope;

/**
 * Attendance is kept by class (AttendanceScope): users who can view all
 * candidates act on every class, others only on the classes they teach.
 * A session of another class is forbidden even when its URL is known.
 */
class AttendanceSessionPolicy
{
    /** View, edit, delete, and record attendance of one session. */
    public function manage(User $actor, AttendanceSession $session): bool
    {
        return $this->allowsClass($actor, $session->class_batch_id);
    }

    /** Create a session for the class. */
    public function create(User $actor, ClassBatch $classBatch): bool
    {
        return $this->allowsClass($actor, $classBatch->id);
    }

    private function allowsClass(User $actor, int $classBatchId): bool
    {
        return $actor->hasPermission(Permission::ManageAttendance) && AttendanceScope::for($actor)->allows($classBatchId);
    }
}
