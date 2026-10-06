<?php

namespace App\Policies;

use App\Models\ClassBatch;
use App\Models\ScheduleEntry;
use App\Models\User;
use App\Services\Schedule\ScheduleScope;

/**
 * Schedule entries are kept by class (ScheduleScope): an entry of a class
 * outside the user's scope is forbidden even when its URL is known.
 */
class ScheduleEntryPolicy
{
    /** Edit or delete the entry. */
    public function manage(User $actor, ScheduleEntry $entry): bool
    {
        return ScheduleScope::canManage($actor, $entry->class_batch_id);
    }

    /** Add an entry to the class's schedule. */
    public function create(User $actor, ClassBatch $classBatch): bool
    {
        return ScheduleScope::canManage($actor, $classBatch->id);
    }
}
