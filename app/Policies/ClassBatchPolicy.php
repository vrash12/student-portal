<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClassBatch;
use App\Models\User;
use App\Services\Attendance\AttendanceScope;

/**
 * Class management itself is protected by the `class_batches.manage`
 * permission on its routes. This policy covers the read-only teaching view.
 */
class ClassBatchPolicy
{
    /**
     * Teaching staff may open a class only while they teach one of its
     * subjects.
     */
    public function viewTeaching(User $actor, ClassBatch $classBatch): bool
    {
        return $actor->teachesClass($classBatch->id);
    }

    /**
     * The printable sheet of the class's QR attendance cards: administrators
     * who manage classes, and staff who keep the class's attendance (the
     * instructors of the class). The campus is checked by the campus middleware.
     */
    /** Every ID card of the class (the administrator side, like CandidatePolicy::viewIdCard). */
    public function printIdCards(User $actor, ClassBatch $classBatch): bool
    {
        return $actor->hasPermission(Permission::AccessStaffArea)
            && $actor->hasPermission(Permission::ViewAllCandidates)
            && $actor->campusScope()->allowsRecord($classBatch);
    }

    /** Every certificate of completion of the class (CandidatePolicy::issueCompletionDocuments). */
    public function printCompletionCertificates(User $actor, ClassBatch $classBatch): bool
    {
        return $actor->hasPermission(Permission::AccessStaffArea)
            && $actor->hasPermission(Permission::ViewAllCandidates)
            && $actor->hasPermission(Permission::ViewPerformance)
            && $actor->campusScope()->allowsRecord($classBatch);
    }

    public function printQrCards(User $actor, ClassBatch $classBatch): bool
    {
        return $actor->hasPermission(Permission::ManageClassBatches)
            || ($actor->hasPermission(Permission::ManageAttendance) && AttendanceScope::for($actor)->allows($classBatch->id));
    }
}
